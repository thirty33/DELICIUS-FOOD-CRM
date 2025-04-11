<?php

namespace App\Jobs;

use App\Mail\OrderEmail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class SendOrdersEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * El tamaño de los chunks para procesar órdenes.
     *
     * @var int
     */
    protected $chunkSize = 10;

    /**
     * Array de IDs de órdenes.
     *
     * @var array
     */
    protected $orderIds;

    /**
     * Create a new job instance.
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
        try {
            Log::info('Iniciando proceso de envío de correos de órdenes', [
                'total_orders' => count($this->orderIds)
            ]);

            // Dividir el array de IDs en chunks para procesamiento más eficiente
            Collection::make($this->orderIds)->chunk($this->chunkSize)->each(function ($chunk) {
                // Obtener las órdenes del chunk actual
                $orders = Order::whereIn('id', $chunk)->get();
                
                foreach ($orders as $order) {
                    try {
                        // Verificar que la orden tenga un usuario asociado con un email
                        if (!$order->user || !$order->user->email) {
                            Log::warning('Orden sin usuario o email válido', [
                                'order_id' => $order->id
                            ]);
                            continue;
                        }
                        
                        // Enviar el correo a través de la cola
                        Mail::to($order->user->email)
                            ->queue(new OrderEmail($order));
                        
                        Log::info('Correo de orden en cola para envío', [
                            'order_id' => $order->id,
                            'user_id' => $order->user->id,
                            'email' => $order->user->email
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Error al encolar correo de orden', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }
            });

            Log::info('Proceso de envío de correos de órdenes completado');
        } catch (\Exception $e) {
            Log::error('Error en el proceso de envío de correos de órdenes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}