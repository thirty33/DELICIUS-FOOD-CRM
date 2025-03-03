<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\ImportProcess;
use App\Models\PriceList;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCompany implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * El número de registro de la empresa.
     *
     * @var string
     */
    private $registrationNumber;

    /**
     * El ID de la lista de precios.
     *
     * @var int
     */
    private $priceListId;

    /**
     * El ID del proceso de importación.
     *
     * @var int
     */
    private $importProcessId;

    /**
     * El índice de la fila en el archivo original.
     *
     * @var int
     */
    private $rowIndex;

    /**
     * Create a new job instance.
     */
    public function __construct(string $registrationNumber, int $priceListId, int $importProcessId, int $rowIndex)
    {
        $this->registrationNumber = $registrationNumber;
        $this->priceListId = $priceListId;
        $this->importProcessId = $importProcessId;
        $this->rowIndex = $rowIndex;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Buscar la empresa por número de registro
            $company = Company::select('id', 'registration_number')
                ->where('registration_number', $this->registrationNumber)
                ->first();

            if (!$company) {
                $this->registerError("No se encontró la empresa con número de registro: {$this->registrationNumber}");
                return;
            }

            // Actualizar la empresa con la lista de precios
            Company::where('id', $company->id)
                ->update(['price_list_id' => $this->priceListId]);

            Log::info('Empresa actualizada con éxito', [
                'registration_number' => $this->registrationNumber,
                'price_list_id' => $this->priceListId
            ]);
        } catch (\Exception $e) {
            $this->registerError("Error procesando empresa {$this->registrationNumber}: " . $e->getMessage());
        }
    }

    /**
     * Registra un error en el proceso de importación.
     */
    private function registerError(string $errorMessage): void
    {
        try {
            // Obtener información básica de la lista de precios para mensajes
            $priceListInfo = PriceList::select('id', 'name')->find($this->priceListId);

            $error = [
                'row' => $this->rowIndex + 2,
                'attribute' => 'company_registration_number',
                'errors' => [$errorMessage],
                'values' => [
                    'registration_number' => $this->registrationNumber,
                    'price_list_id' => $this->priceListId,
                    'price_list_name' => $priceListInfo->name ?? 'Unknown'
                ]
            ];

            // Actualizar el proceso de importación con el error
            $importProcess = ImportProcess::find($this->importProcessId);
            if ($importProcess) {
                $existingErrors = $importProcess->error_log ?? [];
                $existingErrors[] = $error;
                
                $importProcess->update([
                    'error_log' => $existingErrors,
                    'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
                ]);
            }

            Log::warning('Error procesando empresa', [
                'registration_number' => $this->registrationNumber,
                'error' => $errorMessage
            ]);
        } catch (\Exception $e) {
            Log::error('Error al registrar error de procesamiento de empresa', [
                'message' => $e->getMessage(),
                'registration_number' => $this->registrationNumber
            ]);
        }
    }
}