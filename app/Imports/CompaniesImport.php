<?php

namespace App\Imports;

use App\Models\Company;
use App\Models\ImportProcess;
use Filament\Actions\Imports\Models\Import;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Validators\Failure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Throwable;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

class CompaniesImport implements
    ToModel,
    WithHeadingRow,
    WithValidation,
    SkipsOnError,
    SkipsOnFailure,
    WithChunkReading,
    ShouldQueue,
    WithEvents,
    SkipsEmptyRows
{
    use Importable;

    private $importProcessId;
    private $errors = [];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    /**
     * @return array
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                \App\Models\ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_QUEUED]);
            },

            AfterImport::class => function (AfterImport $event) {

                $importProcess = \App\Models\ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update([
                        'status' => ImportProcess::STATUS_PROCESSED
                    ]);
                }
            },
        ];
    }

    public function chunkSize(): int
    {
        return 1;
    }

    /**
     * Convert Excel boolean text to PHP boolean
     */
    private function convertToBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', 'verdadero', 'si', 'yes', '1', 'activo']);
        }

        if (is_numeric($value)) {
            return $value == 1;
        }

        return false;
    }

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        return new Company([
            'company_code' => $row['company_code'],
            'tax_id' => $row['tax_id'],
            'name' => $row['name'],
            'business_activity' => $row['business_activity'] ?? null,
            'fantasy_name' => $row['fantasy_name'],
            'registration_number' => $row['registration_number'] ?? 'REG-' . strtoupper(substr(md5(uniqid()), 0, 8)),
            'acronym' => $row['acronym'] ?? null,
            'address' => $row['address'],
            'shipping_address' => $row['shipping_address'] ?? null,
            'email' => $row['email'],
            'phone_number' => $row['phone_number'],
            'website' => $row['website'] ?? null,
            'contact_name' => $row['contact_name'] ?? null,
            'contact_last_name' => $row['contact_last_name'] ?? null,
            'contact_phone_number' => $row['contact_phone_number'] ?? null,
            'state_region' => $row['state_region'] ?? null,
            'city' => $row['city'] ?? null,
            'country' => $row['country'] ?? null,
            'district' => $row['district'] ?? null,
            'postal_box' => $row['postal_box'] ?? null,
            'zip_code' => $row['zip_code'] ?? null,
            'fax' => $row['fax'] ?? null,
            'payment_condition' => $row['payment_condition'] ?? null,
            'description' => $row['description'],
            'active' => $this->convertToBoolean($row['active'] ?? false),
        ]);
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'company_code' => ['required', 'string', 'min:2', 'max:50', 'unique:companies,company_code'],
            'tax_id' => ['required', 'string', 'unique:companies,tax_id'],
            'name' => ['required', 'string', 'min:2', 'max:200', 'unique:companies,name'],
            'fantasy_name' => ['required', 'string', 'min:2', 'max:200', 'unique:companies,fantasy_name'],
            'registration_number' => ['required', 'string', 'min:2', 'max:200'],
            'address' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'min:2', 'max:200'],
            'phone_number' => ['required', 'string', 'min:2', 'max:200'],
            'description' => ['required', 'string', 'min:2', 'max:200'],

            // Optional fields
            'business_activity' => ['nullable', 'string'],
            'acronym' => ['nullable', 'string'],
            'shipping_address' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'min:2', 'max:200'],
            'contact_name' => ['nullable', 'string'],
            'contact_last_name' => ['nullable', 'string'],
            'contact_phone_number' => ['nullable', 'string'],
            'state_region' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'district' => ['nullable', 'string'],
            'postal_box' => ['nullable', 'string'],
            'zip_code' => ['nullable', 'string'],
            'fax' => ['nullable', 'string'],
            'payment_condition' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param Failure[] $failures
     */
    public function onFailure(Failure ...$failures)
    {
        foreach ($failures as $failure) {
            $error = [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ];

            // Obtener el proceso actual y sus errores existentes
            $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
            $existingErrors = $importProcess->error_log ?? [];

            // Agregar el nuevo error al array existente
            $existingErrors[] = $error;

            // Actualizar el error_log en el ImportProcess
            $importProcess->update([
                'error_log' => $existingErrors,
                'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
            ]);

            Log::warning('Fallo en validación de importación de empresas', [
                'import_process_id' => $this->importProcessId,
                'row_number' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ]);
        }
    }

    public function onError(Throwable $e)
    {
        $error = [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];

        // Obtener el proceso actual y sus errores existentes
        $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];

        // Agregar el nuevo error al array existente
        $existingErrors[] = $error;

        // Actualizar el error_log en el ImportProcess
        $importProcess->update([
            'error_log' => $existingErrors,
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::error('Error en importación de empresas', [
            'import_process_id' => $this->importProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    /**
     * @return array
     */
    public function customValidationMessages()
    {
        return [
            'company_code.required' => 'El código de empresa es requerido',
            'company_code.unique' => 'El código de empresa ya existe',
            'tax_id.required' => 'El RUT es requerido',
            'tax_id.unique' => 'El RUT ya existe',
            'name.required' => 'El nombre es requerido',
            'name.unique' => 'El nombre ya existe',
            'fantasy_name.required' => 'El nombre de fantasía es requerido',
            'fantasy_name.unique' => 'El nombre de fantasía ya existe',
            'registration_number.required' => 'El número de registro es requerido',
            'address.required' => 'La dirección es requerida',
            'email.required' => 'El email es requerido',
            'email.email' => 'El email debe ser válido',
            'phone_number.required' => 'El número de teléfono es requerido',
            'description.required' => 'La descripción es requerida',
            'active.boolean' => 'El campo activo debe ser verdadero o falso',
        ];
    }
}
