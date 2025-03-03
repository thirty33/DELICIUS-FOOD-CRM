<?php

namespace App\Jobs;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BulkDeleteCompanies implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Los IDs de las empresas a eliminar.
     *
     * @var array
     */
    protected $companyIds;

    /**
     * El tamaño del chunk para procesar empresas.
     *
     * @var int
     */
    protected $chunkSize = 50;

    /**
     * Create a new job instance.
     *
     * @param array $companyIds Los IDs de las empresas a eliminar
     */
    public function __construct(array $companyIds)
    {
        $this->companyIds = $companyIds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Iniciando job de eliminación masiva de empresas', [
            'total_companies' => count($this->companyIds),
            'chunk_size' => $this->chunkSize
        ]);

        try {
            // Procesar las empresas en chunks
            $totalProcessed = 0;
            $chunkedCompanyIds = array_chunk($this->companyIds, $this->chunkSize);
            
            Log::info('Dividiendo empresas en chunks para procesamiento', [
                'total_chunks' => count($chunkedCompanyIds)
            ]);

            foreach ($chunkedCompanyIds as $chunkIndex => $chunk) {
                Log::info('Procesando chunk de empresas', [
                    'chunk_index' => $chunkIndex + 1,
                    'chunk_size' => count($chunk)
                ]);

                // Obtener las empresas del chunk actual
                $companies = Company::whereIn('id', $chunk)->get();
                
                Log::info('Empresas encontradas en el chunk actual', [
                    'found_companies' => $companies->count(),
                    'chunk_index' => $chunkIndex + 1
                ]);

                // Eliminar cada empresa con sus relaciones dentro de una transacción
                foreach ($companies as $company) {
                    DB::beginTransaction();
                    try {
                        Log::info('Procesando eliminación de empresa', [
                            'company_id' => $company->id,
                            'company_name' => $company->name,
                            'chunk_index' => $chunkIndex + 1
                        ]);
                        
                        // Eliminar relaciones específicas
                        // 1. Eliminar sucursales
                        $branchesCount = $company->branches()->count();
                        $company->branches()->delete();
                        Log::info('Sucursales eliminadas', [
                            'company_id' => $company->id,
                            'branches_count' => $branchesCount
                        ]);
                                                    
                        // 3. Desvincular usuarios (actualizar foreign key a null)
                        $usersCount = $company->users()->count();
                        if ($usersCount > 0) {
                            $company->users()->update(['company_id' => null]);
                            Log::info('Usuarios desvinculados', [
                                'company_id' => $company->id,
                                'users_count' => $usersCount
                            ]);
                        }
                        
                        // 4. Finalmente eliminar la empresa
                        $company->delete();
                        
                        DB::commit();
                        $totalProcessed++;
                        
                        Log::info('Empresa eliminada correctamente', [
                            'company_id' => $company->id,
                            'company_name' => $company->name,
                            'progress' => "{$totalProcessed}/" . count($this->companyIds)
                        ]);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Error al eliminar empresa en la transacción', [
                            'company_id' => $company->id,
                            'company_name' => $company->name,
                            'chunk_index' => $chunkIndex + 1,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        
                        // Continuamos con la siguiente empresa incluso si esta falló
                        continue;
                    }
                }

                // Liberamos memoria después de cada chunk
                unset($companies);
                
                // Pausa opcional entre chunks para reducir la carga en el servidor
                if (count($chunkedCompanyIds) > 1) {
                    sleep(1);
                }
            }

            Log::info('Job de eliminación masiva de empresas completado', [
                'total_processed' => $totalProcessed,
                'total_expected' => count($this->companyIds)
            ]);
        } catch (\Exception $e) {
            Log::error('Error general en el job de eliminación masiva de empresas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-lanzar la excepción para que el job se marque como fallido
            throw $e;
        }
    }
}