<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DeleteOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Los IDs de las órdenes a eliminar.
     *
     * @var array
     */
    protected $orderIds;

    /**
     * El tamaño del chunk para procesar órdenes.
     *
     * @var int
     */
    protected $chunkSize = 50;

    /**
     * Create a new job instance.
     *
     * @param array $orderIds Los IDs de las órdenes a eliminar
     */
    public function __construct(array $orderIds)
    {
        $this->orderIds = $orderIds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Iniciando job de eliminación masiva de órdenes', [
            'total_orders' => count($this->orderIds),
            'chunk_size' => $this->chunkSize
        ]);

        try {
            // Procesar las órdenes en chunks
            $totalProcessed = 0;
            $chunkedOrderIds = array_chunk($this->orderIds, $this->chunkSize);
            
            Log::info('Dividiendo órdenes en chunks para procesamiento', [
                'total_chunks' => count($chunkedOrderIds)
            ]);

            foreach ($chunkedOrderIds as $chunkIndex => $chunk) {
                Log::info('Procesando chunk de órdenes', [
                    'chunk_index' => $chunkIndex + 1,
                    'chunk_size' => count($chunk)
                ]);

                // Obtener las órdenes del chunk actual
                $orders = Order::whereIn('id', $chunk)->get();
                
                Log::info('Órdenes encontradas en el chunk actual', [
                    'found_orders' => $orders->count(),
                    'chunk_index' => $chunkIndex + 1
                ]);

                // Eliminar cada orden con sus líneas dentro de una transacción
                foreach ($orders as $order) {
                    DB::beginTransaction();
                    try {
                        Log::info('Procesando eliminación de orden', [
                            'order_id' => $order->id,
                            'client' => $order->user->name ?? 'N/A',
                            'chunk_index' => $chunkIndex + 1
                        ]);
                        
                        // 1. Eliminar las líneas de orden
                        $orderLinesCount = $order->orderLines()->count();
                        $order->orderLines()->delete();
                        Log::info('Líneas de orden eliminadas', [
                            'order_id' => $order->id,
                            'order_lines_count' => $orderLinesCount
                        ]);
                        
                        // 2. Finalmente eliminar la orden
                        $order->delete();
                        
                        DB::commit();
                        $totalProcessed++;
                        
                        Log::info('Orden eliminada correctamente', [
                            'order_id' => $order->id,
                            'progress' => "{$totalProcessed}/" . count($this->orderIds)
                        ]);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Error al eliminar orden en la transacción', [
                            'order_id' => $order->id,
                            'chunk_index' => $chunkIndex + 1,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        
                        // Continuamos con la siguiente orden incluso si esta falló
                        continue;
                    }
                }

                // Liberamos memoria después de cada chunk
                unset($orders);
                
                // Pausa opcional entre chunks para reducir la carga en el servidor
                if (count($chunkedOrderIds) > 1) {
                    sleep(1);
                }
            }

            Log::info('Job de eliminación masiva de órdenes completado', [
                'total_processed' => $totalProcessed,
                'total_expected' => count($this->orderIds)
            ]);
        } catch (\Exception $e) {
            Log::error('Error general en el job de eliminación masiva de órdenes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-lanzar la excepción para que el job se marque como fallido
            throw $e;
        }
    }
}