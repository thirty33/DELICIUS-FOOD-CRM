<?php

namespace App\Jobs;

use App\Models\Category;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BulkDeleteCategories implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Los IDs de las categorías a eliminar.
     *
     * @var array
     */
    protected $categoryIds;

    /**
     * El tamaño del chunk para procesar categorías.
     *
     * @var int
     */
    protected $chunkSize = 50;

    /**
     * Create a new job instance.
     *
     * @param array $categoryIds Los IDs de las categorías a eliminar
     */
    public function __construct(array $categoryIds)
    {
        $this->categoryIds = $categoryIds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Iniciando job de eliminación masiva de categorías', [
            'total_categories' => count($this->categoryIds),
            'chunk_size' => $this->chunkSize
        ]);

        try {
            // Procesar las categorías en chunks
            $totalProcessed = 0;
            $chunkedCategoryIds = array_chunk($this->categoryIds, $this->chunkSize);

            Log::info('Dividiendo categorías en chunks para procesamiento', [
                'total_chunks' => count($chunkedCategoryIds)
            ]);

            foreach ($chunkedCategoryIds as $chunkIndex => $chunk) {
                Log::info('Procesando chunk de categorías', [
                    'chunk_index' => $chunkIndex + 1,
                    'chunk_size' => count($chunk)
                ]);

                // Obtener las categorías del chunk actual
                $categories = Category::whereIn('id', $chunk)->get();

                Log::info('Categorías encontradas en el chunk actual', [
                    'found_categories' => $categories->count(),
                    'chunk_index' => $chunkIndex + 1
                ]);

                // Eliminar cada categoría con sus relaciones dentro de una transacción
                foreach ($categories as $category) {
                    DB::beginTransaction();
                    try {
                        Log::info('Procesando eliminación de categoría', [
                            'category_id' => $category->id,
                            'category_name' => $category->name,
                            'chunk_index' => $chunkIndex + 1
                        ]);

                        // 1. Eliminar relaciones con subcategorías
                        $category->subcategories()->detach();
                        Log::info('Relaciones con subcategorías eliminadas', [
                            'category_id' => $category->id
                        ]);

                        // 2. Eliminar categoryLines
                        $categoryLinesCount = $category->categoryLines()->count();
                        $category->categoryLines()->delete();
                        Log::info('CategoryLines eliminadas', [
                            'category_id' => $category->id,
                            'lines_count' => $categoryLinesCount
                        ]);

                        // 3. Eliminar categoryUserLines
                        $categoryUserLinesCount = $category->categoryUserLines()->count();
                        $category->categoryUserLines()->delete();
                        Log::info('CategoryUserLines eliminadas', [
                            'category_id' => $category->id,
                            'user_lines_count' => $categoryUserLinesCount
                        ]);

                        // 4. Desasociar products - actualizar el campo category_id a null
                        $productsCount = $category->products()->count();
                        if ($productsCount > 0) {
                            $category->products()->update(['category_id' => null]);
                            Log::info('Productos desvinculados', [
                                'category_id' => $category->id,
                                'products_count' => $productsCount
                            ]);
                        }

                        // 5. Desasociar menus
                        $menusCount = $category->menus()->count();
                        if ($menusCount > 0) {
                            $category->menus()->detach();
                            Log::info('Relaciones con menús eliminadas', [
                                'category_id' => $category->id,
                                'menus_count' => $menusCount
                            ]);
                        }

                        // 6. Finalmente eliminar la categoría
                        $category->delete();

                        DB::commit();
                        $totalProcessed++;

                        Log::info('Categoría eliminada correctamente', [
                            'category_id' => $category->id,
                            'category_name' => $category->name,
                            'progress' => "{$totalProcessed}/" . count($this->categoryIds)
                        ]);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Error al eliminar categoría en la transacción', [
                            'category_id' => $category->id,
                            'category_name' => $category->name,
                            'chunk_index' => $chunkIndex + 1,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);

                        // Continuamos con la siguiente categoría incluso si esta falló
                        continue;
                    }
                }

                // Liberamos memoria después de cada chunk
                unset($categories);

                // Pausa opcional entre chunks para reducir la carga en el servidor
                if (count($chunkedCategoryIds) > 1) {
                    sleep(1);
                }
            }

            Log::info('Job de eliminación masiva de categorías completado', [
                'total_processed' => $totalProcessed,
                'total_expected' => count($this->categoryIds)
            ]);
        } catch (\Exception $e) {
            Log::error('Error general en el job de eliminación masiva de categorías', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-lanzar la excepción para que el job se marque como fallido
            throw $e;
        }
    }
}
