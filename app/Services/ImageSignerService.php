<?php

namespace App\Services;

use Dreamonkey\CloudFrontUrlSigner\Facades\CloudFrontUrlSigner;
use Illuminate\Support\Carbon;

class ImageSignerService
{
    /**
     * Genera una URL firmada para una imagen en CloudFront
     *
     * @param string $filePath Ruta relativa del archivo en S3
     * @param int $expiryDays Número de días hasta la expiración de la URL
     * @return array Información sobre la URL firmada
     */
    public function getSignedUrl(string $filePath, int $expiryDays = 1): array
    {
        $urlBase = config('app.CLOUDFRONT_URL');
        $fullUrl = "{$urlBase}/{$filePath}";
        
        $signedUrl = CloudFrontUrlSigner::sign($fullUrl, $expiryDays);
        $expiresAt = now()->addDays($expiryDays);
        
        return [
            'original_file' => $filePath,
            'signed_url' => $signedUrl,
            'expires_in' => "{$expiryDays} días",
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'expires_timestamp' => $expiresAt->timestamp
        ];
    }
    
    /**
     * Genera múltiples URLs firmadas para un conjunto de archivos
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
     * Genera una URL firmada para un archivo de producto (con directorio predeterminado)
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