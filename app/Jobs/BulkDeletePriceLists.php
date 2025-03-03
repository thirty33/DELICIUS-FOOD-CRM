<?php

namespace App\Jobs;

use App\Models\PriceList;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BulkDeletePriceLists implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Los IDs de las listas de precios a eliminar.
     *
     * @var array
     */
    protected $priceListIds;

    /**
     * El tamaño del chunk para procesar listas de precios.
     *
     * @var int
     */
    protected $chunkSize = 20;

    /**
     * Create a new job instance.
     *
     * @param array $priceListIds Los IDs de las listas de precios a eliminar
     */
    public function __construct(array $priceListIds)
    {
        $this->priceListIds = $priceListIds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Iniciando job de eliminación masiva de listas de precios', [
            'total_price_lists' => count($this->priceListIds),
            'chunk_size' => $this->chunkSize
        ]);

        try {
            // Procesar las listas de precios en chunks
            $totalProcessed = 0;
            $chunkedPriceListIds = array_chunk($this->priceListIds, $this->chunkSize);
            
            Log::info('Dividiendo listas de precios en chunks para procesamiento', [
                'total_chunks' => count($chunkedPriceListIds)
            ]);

            foreach ($chunkedPriceListIds as $chunkIndex => $chunk) {
                Log::info('Procesando chunk de listas de precios', [
                    'chunk_index' => $chunkIndex + 1,
                    'chunk_size' => count($chunk)
                ]);

                // Obtener las listas de precios del chunk actual
                $priceLists = PriceList::whereIn('id', $chunk)->get();
                
                Log::info('Listas de precios encontradas en el chunk actual', [
                    'found_price_lists' => $priceLists->count(),
                    'chunk_index' => $chunkIndex + 1
                ]);

                // Eliminar cada lista de precios con sus relaciones dentro de una transacción
                foreach ($priceLists as $priceList) {
                    DB::beginTransaction();
                    try {
                        Log::info('Procesando eliminación de lista de precios', [
                            'price_list_id' => $priceList->id,
                            'price_list_name' => $priceList->name,
                            'chunk_index' => $chunkIndex + 1
                        ]);
                        
                        // 1. Manejar las empresas que tienen esta lista de precios
                        $companiesCount = $priceList->companies()->count();
                        if ($companiesCount > 0) {
                            // Actualizar las empresas para que no tengan lista de precios (poner price_list_id en null)
                            $priceList->companies()->update(['price_list_id' => null]);
                            Log::info('Empresas desvinculadas de la lista de precios', [
                                'price_list_id' => $priceList->id,
                                'companies_count' => $companiesCount
                            ]);
                        }
                        
                        // 2. Eliminar lineas de lista de precios
                        $priceListLinesCount = $priceList->priceListLines()->count();
                        if ($priceListLinesCount > 0) {
                            $priceList->priceListLines()->delete();
                            Log::info('Líneas de lista de precios eliminadas', [
                                'price_list_id' => $priceList->id,
                                'price_list_lines_count' => $priceListLinesCount
                            ]);
                        }
                        
                        // 3. Finalmente eliminar la lista de precios
                        $priceList->delete();
                        
                        DB::commit();
                        $totalProcessed++;
                        
                        Log::info('Lista de precios eliminada correctamente', [
                            'price_list_id' => $priceList->id,
                            'price_list_name' => $priceList->name,
                            'progress' => "{$totalProcessed}/" . count($this->priceListIds)
                        ]);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Error al eliminar lista de precios en la transacción', [
                            'price_list_id' => $priceList->id,
                            'price_list_name' => $priceList->name,
                            'chunk_index' => $chunkIndex + 1,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        
                        // Continuamos con la siguiente lista de precios incluso si esta falló
                        continue;
                    }
                }

                // Liberamos memoria después de cada chunk
                unset($priceLists);
                
                // Pausa opcional entre chunks para reducir la carga en el servidor
                if (count($chunkedPriceListIds) > 1) {
                    sleep(1);
                }
            }

            Log::info('Job de eliminación masiva de listas de precios completado', [
                'total_processed' => $totalProcessed,
                'total_expected' => count($this->priceListIds)
            ]);
        } catch (\Exception $e) {
            Log::error('Error general en el job de eliminación masiva de listas de precios', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-lanzar la excepción para que el job se marque como fallido
            throw $e;
        }
    }
}