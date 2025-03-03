<?php

namespace App\Imports;

use App\Models\Company;
use App\Models\ImportProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

class CompaniesImport implements
    ToCollection,
    WithHeadingRow,
    SkipsEmptyRows,
    WithEvents,
    ShouldQueue,
    WithChunkReading,
    WithValidation,
    SkipsOnError,
    SkipsOnFailure
{
    use Importable;

    private $importProcessId;
    private $errors = [];

    private $headingMap = [
        'codigo' => 'company_code',
        'rut' => 'tax_id',
        'razon_social' => 'name',
        'giro' => 'business_activity',
        'nombre_de_fantasia' => 'fantasy_name',
        'numero_de_registro' => 'registration_number',
        'sigla' => 'acronym',
        'direccion' => 'address',
        'direccion_de_despacho' => 'shipping_address',
        'email' => 'email',
        'numero_de_telefono' => 'phone_number',
        'sitio_web' => 'website',
        'nombre_de_contacto' => 'contact_name',
        'apellido_de_contacto' => 'contact_last_name',
        'telefono_de_contacto' => 'contact_phone_number',
        'region' => 'state_region',
        'ciudad' => 'city',
        'pais' => 'country',
        'comuna' => 'district',
        'casilla_postal' => 'postal_box',
        'codigo_postal' => 'zip_code',
        'condicion_de_pago' => 'payment_condition',
        'descripcion' => 'description',
        'activo' => 'active'
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    public function chunkSize(): int
    {
        return 1;
    }

    /**
     * @return array
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                \App\Models\ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_PROCESSING]);
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
     * Get validation rules for import
     * 
     * @return array
     */
    private function getValidationRules(): array
    {
        return [
            '*.codigo' => ['required', 'string', 'min:2', 'max:50'],
            '*.rut' => ['required', 'string'],
            '*.razon_social' => ['required', 'string', 'min:2', 'max:200'],
            '*.nombre_de_fantasia' => ['required', 'string', 'min:2', 'max:200'],
            '*.numero_de_registro' => ['required', 'string', 'min:2', 'max:200'],
            '*.direccion' => ['required', 'string', 'min:2', 'max:200'],
            '*.email' => ['required', 'email', 'min:2', 'max:200'],
            '*.numero_de_telefono' => ['required', 'string', 'min:2', 'max:200'],
            '*.descripcion' => ['required', 'string', 'min:2', 'max:200'],

            // Optional fields
            '*.giro' => ['nullable', 'string'],
            '*.sigla' => ['nullable', 'string'],
            '*.direccion_de_despacho' => ['nullable', 'string'],
            '*.sitio_web' => ['nullable', 'string', 'min:2', 'max:200'],
            '*.nombre_de_contacto' => ['nullable', 'string'],
            '*.apellido_de_contacto' => ['nullable', 'string'],
            '*.telefono_de_contacto' => ['nullable', 'string'],
            '*.region' => ['nullable', 'string'],
            '*.ciudad' => ['nullable', 'string'],
            '*.pais' => ['nullable', 'string'],
            '*.comuna' => ['nullable', 'string'],
            '*.casilla_postal' => ['nullable', 'string'],
            '*.codigo_postal' => ['nullable', 'string'],
            '*.fax' => ['nullable', 'string'],
            '*.condicion_de_pago' => ['nullable', 'string'],
            '*.activo' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0']
        ];
    }

    /**
     * Get custom validation messages
     * 
     * @return array
     */
    private function getValidationMessages(): array
    {
        return [
            '*.codigo.required' => 'El código de empresa es requerido',
            '*.rut.required' => 'El RUT es requerido',
            '*.razon_social.required' => 'El nombre es requerido',
            '*.nombre_de_fantasia.required' => 'El nombre de fantasía es requerido',
            '*.numero_de_registro.required' => 'El número de registro es requerido',
            '*.direccion.required' => 'La dirección es requerida',
            '*.email.required' => 'El email es requerido',
            '*.email.email' => 'El email debe ser válido',
            '*.numero_de_telefono.required' => 'El número de teléfono es requerido',
            '*.descripcion.required' => 'La descripción es requerida',
            '*.activo.in' => 'El campo activo debe ser verdadero o falso',
        ];
    }

    /**
     * @param \Illuminate\Validation\Validator $validator
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            Log::debug('withValidator validation data', [
                'validation_data' => $data
            ]);

            foreach ($data as $index => $row) {
                // Validar RUT único excepto para el registro actual
                if (isset($row['rut'])) {
                    $exists = Company::where('tax_id', $row['rut'])
                        ->where('registration_number', '!=', $row['numero_de_registro'] ?? '')
                        ->exists();

                    if ($exists) {
                        $validator->errors()->add(
                            "{$index}.rut",
                            'El RUT ya existe en otra empresa.'
                        );
                    }
                }

                // Validar email único excepto para el registro actual
                if (isset($row['email'])) {
                    $exists = Company::where('email', $row['email'])
                        ->where('registration_number', '!=', $row['numero_de_registro'] ?? '')
                        ->exists();

                    if ($exists) {
                        $validator->errors()->add(
                            "{$index}.email",
                            'El email ya existe en otra empresa.'
                        );
                    }
                }

                // Validar nombre único excepto para el registro actual
                if (isset($row['razon_social'])) {
                    $exists = Company::where('name', $row['razon_social'])
                        ->where('registration_number', '!=', $row['numero_de_registro'] ?? '')
                        ->exists();

                    if ($exists) {
                        $validator->errors()->add(
                            "{$index}.razon_social",
                            'El nombre ya existe en otra empresa.'
                        );
                    }
                }

                // Validar nombre de fantasía único excepto para el registro actual
                if (isset($row['nombre_de_fantasia'])) {
                    $exists = Company::where('fantasy_name', $row['nombre_de_fantasia'])
                        ->where('registration_number', '!=', $row['numero_de_registro'] ?? '')
                        ->exists();

                    if ($exists) {
                        $validator->errors()->add(
                            "{$index}.nombre_de_fantasia",
                            'El nombre de fantasía ya existe en otra empresa.'
                        );
                    }
                }
            }
        });
    }

    /**
     * Validate and import the collection of rows
     * 
     * @param Collection $rows
     */
    public function collection(Collection $rows)
    {
        try {

            Log::debug('debug rows', [
                '$rows' => $rows,
            ]);

            $rules = $this->getValidationRules();

            Validator::make($rows->toArray(), $rules, $this->getValidationMessages())->validate();

            // Process each row
            foreach ($rows as $index => $row) {

                try {

                    $companyData = $this->prepareCompanyData($row);
                    Company::updateOrCreate(
                        [
                            'registration_number' => $companyData['registration_number'],
                        ],
                        $companyData
                    );

                } catch (\Exception $e) {

                    $error = [
                        'row' => $index + 2,
                        'data' => $row->toArray(),
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ];

                    $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
                    $existingErrors = $importProcess->error_log ?? [];
                    $existingErrors[] = $error;

                    $importProcess->update([
                        'error_log' => $existingErrors,
                        'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
                    ]);

                    Log::error('Error procesando fila en importación de empresas', [
                        'import_process_id' => $this->importProcessId,
                        'row_number' => $index + 2,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        } catch (\Exception $e) {

            $error = [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];

            $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
            $existingErrors = $importProcess->error_log ?? [];
            $existingErrors[] = $error;

            $importProcess->update([
                'error_log' => $existingErrors,
                'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
            ]);

            Log::error('Error general en importación de empresas', [
                'import_process_id' => $this->importProcessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Prepare company data from a row
     * 
     * @param Collection $row
     * @return array
     */
    private function prepareCompanyData(Collection $row): array
    {
        return [
            'company_code' => $row['codigo'],
            'tax_id' => $row['rut'],
            'name' => $row['razon_social'],
            'business_activity' => $row['giro'] ?? null,
            'fantasy_name' => $row['nombre_de_fantasia'],
            'registration_number' => $row['numero_de_registro'] ?? 'REG-' . strtoupper(substr(md5(uniqid()), 0, 8)),
            'acronym' => $row['sigla'] ?? null,
            'address' => $row['direccion'],
            'shipping_address' => $row['direccion_de_despacho'] ?? null,
            'email' => $row['email'],
            'phone_number' => $row['numero_de_telefono'],
            'website' => $row['sitio_web'] ?? null,
            'contact_name' => $row['nombre_de_contacto'] ?? null,
            'contact_last_name' => $row['apellido_de_contacto'] ?? null,
            'contact_phone_number' => $row['telefono_de_contacto'] ?? null,
            'state_region' => $row['region'] ?? null,
            'city' => $row['ciudad'] ?? null,
            'country' => $row['pais'] ?? null,
            'district' => $row['comuna'] ?? null,
            'postal_box' => $row['casilla_postal'] ?? null,
            'zip_code' => $row['codigo_postal'] ?? null,
            'fax' => $row['fax'] ?? null,
            'payment_condition' => $row['condicion_de_pago'] ?? null,
            'description' => $row['descripcion'],
            'active' => $this->convertToBoolean($row['activo'] ?? false),
        ];
    }

    /**
     * Validation rules
     * 
     * @return array
     */
    public function rules(): array
    {
        return [
            '*.codigo' => ['required', 'string', 'min:2', 'max:50'],
            '*.rut' => ['required', 'string'],
            '*.razon_social' => ['required', 'string', 'min:2', 'max:200'],
            '*.nombre_de_fantasia' => ['required', 'string', 'min:2', 'max:200'],
            '*.numero_de_registro' => ['required', 'string', 'min:2', 'max:200'],
            '*.direccion' => ['required', 'string', 'min:2', 'max:200'],
            '*.email' => ['required', 'email', 'min:2', 'max:200'],
            '*.numero_de_telefono' => ['required', 'string', 'min:2', 'max:200'],
            '*.descripcion' => ['required', 'string', 'min:2', 'max:200'],

            // Optional fields
            '*.giro' => ['nullable', 'string'],
            '*.sigla' => ['nullable', 'string'],
            '*.direccion_de_despacho' => ['nullable', 'string'],
            '*.sitio_web' => ['nullable', 'string', 'min:2', 'max:200'],
            '*.nombre_de_contacto' => ['nullable', 'string'],
            '*.apellido_de_contacto' => ['nullable', 'string'],
            '*.telefono_de_contacto' => ['nullable', 'string'],
            '*.region' => ['nullable', 'string'],
            '*.ciudad' => ['nullable', 'string'],
            '*.pais' => ['nullable', 'string'],
            '*.comuna' => ['nullable', 'string'],
            '*.casilla_postal' => ['nullable', 'string'],
            '*.codigo_postal' => ['nullable', 'string'],
            '*.fax' => ['nullable', 'string'],
            '*.condicion_de_pago' => ['nullable', 'string'],
            '*.activo' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0']
        ];
    }

    /**
     * Custom validation messages
     * 
     * @return array
     */
    public function customValidationMessages(): array
    {
        return [
            '*.codigo.required' => 'El código de empresa es requerido',
            '*.rut.required' => 'El RUT es requerido',
            '*.razon_social.required' => 'El nombre es requerido',
            '*.nombre_de_fantasia.required' => 'El nombre de fantasía es requerido',
            '*.numero_de_registro.required' => 'El número de registro es requerido',
            '*.direccion.required' => 'La dirección es requerida',
            '*.email.required' => 'El email es requerido',
            '*.email.email' => 'El email debe ser válido',
            '*.numero_de_telefono.required' => 'El número de teléfono es requerido',
            '*.descripcion.required' => 'La descripción es requerida',
            '*.activo.in' => 'El campo activo debe ser verdadero o falso',
        ];
    }

    /**
     * Handle validation failures
     * 
     * @param Failure ...$failures
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

    /**
     * Handle import errors
     * 
     * @param Throwable $e
     */
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
}
