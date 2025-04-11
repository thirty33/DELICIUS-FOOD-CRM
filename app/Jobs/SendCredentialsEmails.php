<?php

namespace App\Jobs;

use App\Mail\UserCredentialsEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class SendCredentialsEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * El tamaño de los chunks para procesar usuarios.
     *
     * @var int
     */
    protected $chunkSize = 10;

    /**
     * Array de IDs de usuarios.
     *
     * @var array
     */
    protected $userIds;

    /**
     * Create a new job instance.
     */
    public function __construct(array $userIds)
    {
        $this->userIds = $userIds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Iniciando proceso de envío de credenciales', [
                'total_users' => count($this->userIds)
            ]);

            // Dividir el array de IDs en chunks para procesamiento más eficiente
            Collection::make($this->userIds)->chunk($this->chunkSize)->each(function ($chunk) {
                // Obtener los usuarios del chunk actual
                $users = User::whereIn('id', $chunk)->get();
                
                foreach ($users as $user) {
                    try {
                        // Enviar el correo a través de la cola
                        Mail::to($user->email)
                            ->queue(new UserCredentialsEmail($user));
                        
                        Log::info('Correo de credenciales en cola para envío', [
                            'user_id' => $user->id,
                            'email' => $user->email
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Error al encolar correo de credenciales', [
                            'user_id' => $user->id,
                            'email' => $user->email,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }
            });

            Log::info('Proceso de envío de credenciales completado');
        } catch (\Exception $e) {
            Log::error('Error en el proceso de envío de credenciales', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}