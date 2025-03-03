<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BulkDeleteProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Los IDs de los productos a eliminar.
     *
     * @var array
     */
    protected $productIds;

    /**
     * El tamaño del chunk para procesar productos.
     *
     * @var int
     */
    protected $chunkSize = 50;

    /**
     * Create a new job instance.
     *
     * @param array $productIds Los IDs de los productos a eliminar
     */
    public function __construct(array $productIds)
    {
        $this->productIds = $productIds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Iniciando job de eliminación masiva de productos', [
            'total_products' => count($this->productIds),
            'chunk_size' => $this->chunkSize
        ]);

        try {
            // Procesar los productos en chunks
            $totalProcessed = 0;
            $chunkedProductIds = array_chunk($this->productIds, $this->chunkSize);

            Log::info('Dividiendo productos en chunks para procesamiento', [
                'total_chunks' => count($chunkedProductIds)
            ]);

            foreach ($chunkedProductIds as $chunkIndex => $chunk) {
                Log::info('Procesando chunk de productos', [
                    'chunk_index' => $chunkIndex + 1,
                    'chunk_size' => count($chunk)
                ]);

                // Obtener los productos del chunk actual
                $products = Product::whereIn('id', $chunk)->get();

                Log::info('Productos encontrados en el chunk actual', [
                    'found_products' => $products->count(),
                    'chunk_index' => $chunkIndex + 1
                ]);

                // Eliminar cada producto con sus relaciones dentro de una transacción
                foreach ($products as $product) {
                    DB::beginTransaction();
                    try {
                        Log::info('Procesando eliminación de producto', [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_code' => $product->code,
                            'chunk_index' => $chunkIndex + 1
                        ]);

                        // 1. Eliminar relaciones con CategoryMenus
                        $categoryMenuCount = $product->CategoryMenus()->count();
                        if ($categoryMenuCount > 0) {
                            $product->CategoryMenus()->detach();
                            Log::info('Relaciones con CategoryMenus eliminadas', [
                                'product_id' => $product->id,
                                'category_menu_count' => $categoryMenuCount
                            ]);
                        }

                        // 2. Eliminar líneas de lista de precios
                        $priceListLinesCount = $product->priceListLines()->count();
                        if ($priceListLinesCount > 0) {
                            $product->priceListLines()->delete();
                            Log::info('Líneas de lista de precios eliminadas', [
                                'product_id' => $product->id,
                                'price_list_lines_count' => $priceListLinesCount
                            ]);
                        }

                        // 3. Eliminar ingredientes
                        $ingredientsCount = $product->ingredients()->count();
                        if ($ingredientsCount > 0) {
                            $product->ingredients()->delete();
                            Log::info('Ingredientes eliminados', [
                                'product_id' => $product->id,
                                'ingredients_count' => $ingredientsCount
                            ]);
                        }

                        // 4. Finalmente eliminar el producto
                        $product->delete();

                        DB::commit();
                        $totalProcessed++;

                        Log::info('Producto eliminado correctamente', [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_code' => $product->code,
                            'progress' => "{$totalProcessed}/" . count($this->productIds)
                        ]);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Error al eliminar producto en la transacción', [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_code' => $product->code,
                            'chunk_index' => $chunkIndex + 1,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);

                        // Continuamos con el siguiente producto incluso si este falló
                        continue;
                    }
                }

                // Liberamos memoria después de cada chunk
                unset($products);

                // Pausa opcional entre chunks para reducir la carga en el servidor
                if (count($chunkedProductIds) > 1) {
                    sleep(1);
                }
            }

            Log::info('Job de eliminación masiva de productos completado', [
                'total_processed' => $totalProcessed,
                'total_expected' => count($this->productIds)
            ]);
        } catch (\Exception $e) {
            Log::error('Error general en el job de eliminación masiva de productos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-lanzar la excepción para que el job se marque como fallido
            throw $e;
        }
    }
}
