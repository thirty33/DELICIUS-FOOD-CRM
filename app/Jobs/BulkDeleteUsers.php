<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkDeleteUsers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Los IDs de usuarios a eliminar.
     *
     * @var array
     */
    protected $userIds;

    /**
     * El tamaño del chunk para procesar usuarios.
     *
     * @var int
     */
    protected $chunkSize = 50;

    /**
     * Create a new job instance.
     *
     * @param array $userIds Los IDs de usuarios a eliminar
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
        Log::info('Iniciando job de eliminación masiva de usuarios', [
            'total_users' => count($this->userIds),
            'chunk_size' => $this->chunkSize
        ]);

        try {
            // Procesar los usuarios en chunks
            $totalProcessed = 0;
            $chunkedUserIds = array_chunk($this->userIds, $this->chunkSize);
            
            Log::info('Dividiendo usuarios en chunks para procesamiento', [
                'total_chunks' => count($chunkedUserIds)
            ]);

            foreach ($chunkedUserIds as $chunkIndex => $chunk) {
                Log::info('Procesando chunk de usuarios', [
                    'chunk_index' => $chunkIndex + 1,
                    'chunk_size' => count($chunk)
                ]);

                // Obtener los usuarios del chunk actual
                $users = User::whereIn('id', $chunk)->get();
                
                Log::info('Usuarios encontrados en el chunk actual', [
                    'found_users' => $users->count(),
                    'chunk_index' => $chunkIndex + 1
                ]);

                // Eliminar cada usuario con sus relaciones dentro de una transacción
                foreach ($users as $user) {
                    DB::beginTransaction();
                    try {
                        Log::info('Procesando eliminación de usuario', [
                            'user_id' => $user->id,
                            'chunk_index' => $chunkIndex + 1
                        ]);
                        
                        // Eliminar relaciones específicas
                        // 1. Eliminar relaciones con roles
                        $user->roles()->detach();
                        
                        // 2. Eliminar relaciones con permisos
                        $user->permissions()->detach();
                        
                        // 3. Eliminar líneas de categoría del usuario
                        $user->categoryUserLines()->delete();
                        
                        // 4. Finalmente eliminar el usuario
                        $user->delete();
                        
                        DB::commit();
                        $totalProcessed++;
                        
                        Log::info('Usuario eliminado correctamente', [
                            'user_id' => $user->id,
                            'progress' => "{$totalProcessed}/" . count($this->userIds)
                        ]);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Error al eliminar usuario en la transacción', [
                            'user_id' => $user->id,
                            'chunk_index' => $chunkIndex + 1,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        
                        // Continuamos con el siguiente usuario incluso si este falló
                        continue;
                    }
                }

                // Liberamos memoria después de cada chunk
                unset($users);
                
                // Pausa opcional entre chunks para reducir la carga en el servidor
                if (count($chunkedUserIds) > 1) {
                    sleep(1);
                }
            }

            Log::info('Job de eliminación masiva de usuarios completado', [
                'total_processed' => $totalProcessed,
                'total_expected' => count($this->userIds)
            ]);
        } catch (\Exception $e) {
            Log::error('Error general en el job de eliminación masiva de usuarios', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-lanzar la excepción para que el job se marque como fallido
            throw $e;
        }
    }
}