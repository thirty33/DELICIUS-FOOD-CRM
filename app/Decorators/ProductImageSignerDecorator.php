<?php

namespace App\Decorators;

use App\Models\Product;
use App\Services\ImageSignerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProductImageSignerDecorator
{
    /**
     * La instancia del servicio original
     *
     * @var ImageSignerService
     */
    protected $imageSigner;

    /**
     * Constructor del decorador
     *
     * @param ImageSignerService $imageSigner
     */
    public function __construct(ImageSignerService $imageSigner)
    {
        $this->imageSigner = $imageSigner;
    }

    /**
     * Obtiene una URL firmada manteniendo la firma del método original
     * Busca productos por nombre de imagen y maneja sus URLs firmadas
     * Considera la zona horaria configurada en APP_TIMEZONE
     *
     * @param string $filePath Ruta del archivo (se usará para buscar productos)
     * @param int $expiryDays Días hasta expiración
     * @return array Información de la URL firmada
     */
    public function getSignedUrl(string $filePath, int $expiryDays = 1): array
    {
        // Extraer solo el nombre del archivo de la ruta completa
        $fileName = basename($filePath);

        // Obtener la zona horaria configurada en .env
        $appTimezone = config('app.timezone', 'UTC');

        // Buscar productos que usen esta imagen
        // Buscar coincidencia exacta primero, luego parcial si es necesario
        $products = Product::where('image', $filePath)->get();
        
        if ($products->isEmpty()) {
            // Si no hay coincidencia exacta, buscar por nombre de archivo
            $products = Product::where('image', 'like', "%{$fileName}%")->get();
        }

        // Si no hay productos asociados, simplemente delegar al servicio original
        if ($products->isEmpty()) {
            return $this->imageSigner->getSignedUrl($filePath, $expiryDays);
        }

        // Check if any product already has a valid signed URL
        foreach ($products as $product) {
            if ($product->cloudfront_signed_url && $product->signed_url_expiration) {
                // Verify that the product image matches the requested one
                // This is important to avoid returning URLs from old images
                if ($product->image !== $filePath && !str_contains($product->image, $fileName)) {
                    Log::warning('Product image mismatch in CloudFront URL cache', [
                        'product_id' => $product->id,
                        'requested_file' => $filePath,
                        'product_image' => $product->image
                    ]);
                    continue;
                }
                
                // Convert expiration date to timestamp considering the application timezone
                $expirationDateTime = Carbon::parse($product->signed_url_expiration, $appTimezone);
                $expirationTimestamp = $expirationDateTime->timestamp;

                // Get current timestamp considering the application timezone
                $nowDateTime = Carbon::now($appTimezone);
                $nowTimestamp = $nowDateTime->timestamp;

                // Si la URL no ha expirado, usarla
                if ($expirationTimestamp > $nowTimestamp) {
                    $diffDays = (int) $nowDateTime->diffInDays($expirationDateTime);

                    return [
                        'original_file' => $filePath,
                        'signed_url' => $product->cloudfront_signed_url,
                        'expires_in' => "{$diffDays} días",
                        'expires_at' => $expirationDateTime->format('Y-m-d H:i:s'),
                        'expires_timestamp' => $expirationTimestamp,
                        'current_timestamp' => $nowTimestamp,
                        'from_cache' => true,
                        'product_id' => $product->id,
                        'timezone' => $appTimezone
                    ];
                }
            }
        }

        // No product has a valid URL, create a new one
        $signedUrlData = $this->imageSigner->getSignedUrl($filePath, $expiryDays);

        // Actualizar todos los productos que usan esta imagen
        foreach ($products as $product) {
            // Solo actualizar si la imagen coincide
            if ($product->image === $filePath || str_contains($product->image, $fileName)) {
                // Create expiration date using Carbon with the correct timezone
                $expiresAt = Carbon::createFromTimestamp($signedUrlData['expires_timestamp'], $appTimezone);

                $product->cloudfront_signed_url = $signedUrlData['signed_url'];
                $product->signed_url_expiration = $expiresAt;

                try {
                    // Usar saveQuietly para evitar disparar el observer
                    $product->saveQuietly();
                    
                    Log::info('Updated CloudFront URL for product', [
                        'product_id' => $product->id,
                        'image' => $product->image,
                        'expires_at' => $expiresAt->format('Y-m-d H:i:s')
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to update CloudFront URL for product', [
                        'product_id' => $product->id,
                        'error' => $e->getMessage()
                    ]);
                    // Continuar con el siguiente producto si hay un error
                    continue;
                }
            }
        }

        // Add additional information to the result
        $signedUrlData['from_cache'] = false;
        $signedUrlData['product_count'] = $products->count();
        $signedUrlData['product_ids'] = $products->pluck('id')->toArray();
        $signedUrlData['timezone'] = $appTimezone;
        $signedUrlData['current_timestamp'] = Carbon::now($appTimezone)->timestamp;

        return $signedUrlData;
    }

    /**
     * Método proxy para acceder a getMultipleSignedUrls del servicio original
     * con funcionalidad añadida para actualizar productos
     *
     * @param array $filePaths Lista de rutas de archivos
     * @param int $expiryDays Número de días hasta la expiración de las URLs
     * @return array Información sobre las URLs firmadas
     */
    public function getMultipleSignedUrls(array $filePaths, int $expiryDays = 1): array
    {
        $results = [];

        foreach ($filePaths as $path) {
            $results[$path] = $this->getSignedUrl($path, $expiryDays);
        }

        return $results;
    }

    /**
     * Método proxy para acceder a getProductImageUrl del servicio original
     * con funcionalidad añadida para actualizar productos
     *
     * @param string $fileName Nombre del archivo sin la ruta
     * @param int $expiryDays Número de días hasta la expiración de la URL
     * @return array Información sobre la URL firmada
     */
    public function getProductImageUrl(string $fileName, int $expiryDays = 1): array
    {
        return $this->getSignedUrl("product-images/{$fileName}", $expiryDays);
    }
}
