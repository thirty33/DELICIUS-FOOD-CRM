<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\Role;
use App\Models\Permission;
use App\Models\ImportProcess;
use App\Classes\Menus\MenuHelper;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Classes\ErrorManagment\ExportErrorHandler;
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
use Carbon\Carbon;

class MenusImport implements
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
        'titulo' => 'title',
        'descripcion' => 'description',
        'fecha_de_despacho' => 'publication_date',
        'tipo_de_usuario' => 'role_id',
        'tipo_de_convenio' => 'permissions_id',
        'fecha_hora_maxima_pedido' => 'max_order_date',
        'activo' => 'active',
        'empresas_asociadas' => 'companies',
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    // Accepted formats for maximum date and time
    private $dateTimeFormats = [
        'd/m/Y H:i:s',  // With seconds: 01/01/2025 14:30:00
        'd/m/Y H:i'     // Without seconds: 01/01/2025 14:30
    ];

    public function chunkSize(): int
    {
        return 100; // Increased for better performance
    }

    /**
     * @return array
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_PROCESSING]);

                Log::info('Iniciando importación de menús', ['process_id' => $this->importProcessId]);
            },

            AfterImport::class => function (AfterImport $event) {
                $importProcess = ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update([
                        'status' => ImportProcess::STATUS_PROCESSED
                    ]);
                }

                Log::info('Finalizada importación de menús', ['process_id' => $this->importProcessId]);
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
     * Clean description field, treating '-' as empty
     */
    private function cleanDescriptionField($description): ?string
    {
        if (empty($description)) {
            return null;
        }

        $description = trim($description);

        if ($description === '-') {
            return null;
        }

        return $description;
    }

    /**
     * Parse company registration numbers from string
     */
    private function parseCompanyRegistrationNumbers($companiesString): array
    {
        if (empty($companiesString)) {
            return [];
        }

        $companiesString = trim($companiesString);

        if ($companiesString === '-') {
            return [];
        }

        // Split by comma and clean each registration number
        $registrationNumbers = array_map('trim', explode(',', $companiesString));

        // Remove empty values
        return array_filter($registrationNumbers, function($value) {
            return !empty($value) && $value !== '-';
        });
    }

    /**
     * Get validation rules for import
     *
     * @return array
     */
    private function getValidationRules(): array
    {
        return [
            '*.titulo' => ['required', 'string', 'min:2', 'max:255'],
            '*.descripcion' => ['nullable', 'string', 'max:200'],
            '*.fecha_de_despacho' => ['required'],
            '*.tipo_de_usuario' => ['required', 'string', 'exists:roles,name'],
            '*.tipo_de_convenio' => ['required', 'string', 'exists:permissions,name'],
            '*.fecha_hora_maxima_pedido' => ['required'],
            '*.activo' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0,si,no,yes,no'],
            '*.empresas_asociadas' => ['nullable', 'string']
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
            '*.titulo.required' => 'El título es obligatorio.',
            '*.titulo.min' => 'El título debe tener al menos 2 caracteres.',
            '*.titulo.max' => 'El título no debe exceder los 255 caracteres.',
            '*.descripcion.string' => 'La descripción debe ser texto.',
            '*.descripcion.max' => 'La descripción no debe exceder los 200 caracteres.',
            '*.fecha_de_despacho.required' => 'La fecha de despacho es obligatoria.',
            '*.tipo_de_usuario.required' => 'El tipo de usuario es obligatorio.',
            '*.tipo_de_usuario.exists' => 'El tipo de usuario especificado no existe.',
            '*.tipo_de_convenio.required' => 'El tipo de convenio es obligatorio.',
            '*.tipo_de_convenio.exists' => 'El tipo de convenio especificado no existe.',
            '*.fecha_hora_maxima_pedido.required' => 'La fecha y hora máxima de pedido es obligatoria.',
            '*.fecha_hora_maxima_pedido.string' => 'La fecha y hora máxima de pedido debe ser texto.',
            '*.activo.in' => 'El campo activo debe tener un valor válido (si/no, verdadero/falso, 1/0).',
        ];
    }

    /**
     * @param \Illuminate\Validation\Validator $validator
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            Log::debug('MenusImport withValidator validation data', [
                'validation_data' => count($data) > 0 ? array_slice($data, 0, 1) : [],
                'count' => count($data)
            ]);

            foreach ($data as $index => $row) {
                // Verify that fecha_hora_maxima_pedido is present
                if (!isset($row['fecha_hora_maxima_pedido'])) {
                    $validator->errors()->add(
                        "{$index}.fecha_hora_maxima_pedido",
                        'La fecha y hora máxima de pedido es obligatoria.'
                    );
                }

                // Validate unique combination of date-role-permission-active-companies
                if (isset($row['fecha_de_despacho'], $row['tipo_de_usuario'], $row['tipo_de_convenio'], $row['fecha_hora_maxima_pedido'])) {
                    try {
                        // Process publication date
                        $parsedDate = $this->parseFlexibleDate($row['fecha_de_despacho']);
                        $publicationDate = $parsedDate ? $parsedDate->format('Y-m-d') : null;

                        // Process max order date
                        $maxOrderDate = $this->parseFlexibleDateTime($row['fecha_hora_maxima_pedido']);
                        $maxOrderDateFormatted = $maxOrderDate ? $maxOrderDate->format('Y-m-d H:i:s') : null;

                        // Get active status
                        $active = $this->convertToBoolean($row['activo'] ?? true);

                        // Find role and permission
                        $role = Role::where('name', $row['tipo_de_usuario'])->first();
                        $permission = Permission::where('name', $row['tipo_de_convenio'])->first();

                        // Get company IDs from registration numbers
                        $companyIds = [];
                        if (isset($row['empresas_asociadas'])) {
                            $registrationNumbers = $this->parseCompanyRegistrationNumbers($row['empresas_asociadas']);
                            if (!empty($registrationNumbers)) {
                                $companyIds = \App\Models\Company::whereIn('registration_number', $registrationNumbers)
                                    ->pluck('id')
                                    ->toArray();
                            }
                        }

                        if ($role && $permission && $maxOrderDate) {
                            // Find existing menu with the same title to get its ID (for updates)
                            $existingMenu = Menu::where('title', $row['titulo'])->first();
                            $excludeId = $existingMenu ? $existingMenu->id : null;

                            $duplicate = MenuHelper::checkDuplicateMenuForImport(
                                $publicationDate,
                                $role->id,
                                $permission->id,
                                $active,
                                $maxOrderDateFormatted,
                                $companyIds,
                                $excludeId
                            );

                            if ($duplicate) {
                                $validator->errors()->add(
                                    "{$index}.combinacion",
                                    'Ya existe un menú con la misma combinación de Fecha de despacho, Tipo de usuario, Tipo de Convenio, estado Activo, Fecha hora máxima de pedido y empresas asociadas.'
                                );
                            }
                        }
                    } catch (\Exception $e) {
                        $validator->errors()->add(
                            "{$index}.fecha_formato",
                            'Error al validar la combinación única. Verifique el formato de las fechas (fecha de despacho: DD/MM/YYYY, fecha hora máxima: DD/MM/YYYY HH:MM:SS o DD/MM/YYYY HH:MM).'
                        );
                    }
                }

                // Validate dispatch date format
                if (!empty($row['fecha_de_despacho'])) {
                    $parsedDate = $this->parseFlexibleDate($row['fecha_de_despacho']);
                    if (!$parsedDate) {
                        $validator->errors()->add(
                            "{$index}.fecha_de_despacho",
                            'El formato de la fecha de despacho debe ser DD/MM/YYYY o un número válido de Excel.'
                        );
                    }
                }

                // Validate maximum order date and time format
                if (!empty($row['fecha_hora_maxima_pedido'])) {
                    $dateTime = $this->parseFlexibleDateTime($row['fecha_hora_maxima_pedido']);

                    if (!$dateTime) {
                        Log::warning('Error al validar fecha y hora máxima', [
                            'row' => $index,
                            'value' => $row['fecha_hora_maxima_pedido']
                        ]);
                        $validator->errors()->add(
                            "{$index}.fecha_hora_maxima_pedido",
                            'El formato de la fecha y hora máxima de pedido debe ser DD/MM/YYYY HH:MM:SS, DD/MM/YYYY HH:MM o un número válido de Excel.'
                        );
                    }
                }
            }
        });
    }

    /**
     * Convert Excel serial number to Carbon date
     * Excel counts days since January 1, 1900 (with a leap year bug)
     */
    private function convertExcelSerialToDate($serial): ?Carbon
    {
        if (!is_numeric($serial)) {
            return null;
        }
        
        try {
            // Excel's epoch starts at 1900-01-01, but there's a leap year bug
            // Excel thinks 1900 was a leap year (it wasn't)
            $excelEpoch = Carbon::create(1900, 1, 1);
            
            // Adjust for Excel's leap year bug (day 60 = Feb 29, 1900 which didn't exist)
            if ($serial >= 60) {
                $serial -= 1;
            }
            
            // Convert serial to date
            return $excelEpoch->addDays($serial - 1);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse date that can be either Excel serial number or formatted string
     */
    private function parseFlexibleDate($dateValue): ?Carbon
    {
        if (empty($dateValue)) {
            return null;
        }

        // Remove leading quote if present
        if (is_string($dateValue) && substr($dateValue, 0, 1) === "'") {
            $dateValue = substr($dateValue, 1);
        }

        // Try Excel serial number first
        if (is_numeric($dateValue)) {
            $serialDate = $this->convertExcelSerialToDate($dateValue);
            if ($serialDate) {
                return $serialDate;
            }
        }

        // Try string parsing if not numeric or serial conversion failed
        if (is_string($dateValue)) {
            try {
                return Carbon::createFromFormat('d/m/Y', $dateValue);
            } catch (\Exception $e) {
                // Try other common formats
                $formats = ['Y-m-d', 'd-m-Y', 'm/d/Y'];
                foreach ($formats as $format) {
                    try {
                        return Carbon::createFromFormat($format, $dateValue);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse datetime that can be either Excel serial number or formatted string
     */
    private function parseFlexibleDateTime($dateTimeValue): ?Carbon
    {
        if (empty($dateTimeValue)) {
            return null;
        }

        // Remove leading quote if present
        if (is_string($dateTimeValue) && substr($dateTimeValue, 0, 1) === "'") {
            $dateTimeValue = substr($dateTimeValue, 1);
        }

        // Try Excel serial number first (includes time as decimal)
        if (is_numeric($dateTimeValue)) {
            $serialDate = $this->convertExcelSerialToDate(floor($dateTimeValue));
            if ($serialDate) {
                // Extract time from decimal part
                $timeFraction = $dateTimeValue - floor($dateTimeValue);
                $totalMinutes = $timeFraction * 24 * 60;
                $hours = floor($totalMinutes / 60);
                $minutes = floor($totalMinutes % 60);
                $seconds = floor(($totalMinutes - floor($totalMinutes)) * 60);
                
                return $serialDate->setTime($hours, $minutes, $seconds);
            }
        }

        // Try string parsing
        return $this->parseWithMultipleFormats($dateTimeValue, $this->dateTimeFormats);
    }

    private function parseWithMultipleFormats($dateString, array $formats): ?Carbon
    {
        if (is_string($dateString) && substr($dateString, 0, 1) === "'") {
            $dateString = substr($dateString, 1);
        }

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $dateString);
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Validate and import the collection of rows
     * 
     * @param Collection $rows
     */
    public function collection(Collection $rows)
    {
        try {
            Log::info('MenusImport procesando colección', [
                'count' => $rows->count()
            ]);

            // Sample data for debugging
            if ($rows->count() > 0) {
                Log::debug('Muestra de datos:', [
                    'primera_fila' => $rows->first()->toArray(),
                    'columnas' => array_keys($rows->first()->toArray())
                ]);
            }

            // Process each row
            foreach ($rows as $index => $row) {
                try {
                    // Verify that fecha_hora_maxima_pedido is present
                    if (!isset($row['fecha_hora_maxima_pedido'])) {
                        throw new \Exception('La fecha y hora máxima de pedido es obligatoria.');
                    }

                    // Verify that all required fields are present and not empty
                    $requiredFields = ['titulo', 'descripcion', 'fecha_de_despacho', 'tipo_de_usuario', 'tipo_de_convenio', 'fecha_hora_maxima_pedido'];
                    foreach ($requiredFields as $field) {
                        if (!isset($row[$field]) || empty($row[$field])) {
                            throw new \Exception("El campo {$field} es requerido y no puede estar vacío");
                        }
                    }

                    $menuData = $this->prepareMenuData($row);

                    // Use updateOrCreate with title as identifying key
                    $menu = Menu::updateOrCreate(
                        ['title' => $menuData['title']],
                        $menuData
                    );

                    // Handle company associations
                    $this->handleCompanyAssociations($menu, $row);

                    Log::info('Menú creado/actualizado con éxito: ' . $menuData['title']);
                } catch (\Exception $e) {
                    // Use standard error format through ExportErrorHandler
                    ExportErrorHandler::handle(
                        $e,
                        $this->importProcessId,
                        'row_' . ($index + 2),
                        'ImportProcess'
                    );

                    Log::error('Error procesando fila ' . ($index + 2), [
                        'error' => $e->getMessage(),
                        'data' => isset($row) ? $row->toArray() : 'No row data'
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Use standard error format through ExportErrorHandler
            ExportErrorHandler::handle(
                $e,
                $this->importProcessId,
                'general_validation',
                'ImportProcess'
            );

            Log::error('Error general en la importación', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Prepare menu data from a row
     * 
     * @param Collection $row
     * @return array
     */
    private function prepareMenuData(Collection $row): array
    {
        // Format dates
        $publicationDate = null;
        if (!empty($row['fecha_de_despacho'])) {
            $parsedDate = $this->parseFlexibleDate($row['fecha_de_despacho']);
            if ($parsedDate) {
                $publicationDate = $parsedDate->format('Y-m-d');
            } else {
                Log::error('Error al parsear fecha de despacho', [
                    'value' => $row['fecha_de_despacho']
                ]);
                throw new \Exception('El formato de la fecha de despacho es incorrecto. Debe ser DD/MM/YYYY o un número válido de Excel.');
            }
        }

        $maxOrderDate = null;
        if (!empty($row['fecha_hora_maxima_pedido'])) {
            $dateTime = $this->parseFlexibleDateTime($row['fecha_hora_maxima_pedido']);

            if ($dateTime) {
                // Convert to standard format Y-m-d H:i:s, ensuring it has seconds
                $maxOrderDate = $dateTime->format('Y-m-d H:i:s');
                Log::debug('Fecha y hora máxima parseada correctamente', [
                    'original' => $row['fecha_hora_maxima_pedido'],
                    'parseada' => $maxOrderDate
                ]);
            } else {
                Log::error('Error al parsear fecha y hora máxima de pedido', [
                    'value' => $row['fecha_hora_maxima_pedido']
                ]);
                throw new \Exception('El formato de la fecha y hora máxima de pedido es incorrecto. Debe ser DD/MM/YYYY HH:MM:SS, DD/MM/YYYY HH:MM o un número válido de Excel.');
            }
        } else {
            throw new \Exception('La fecha y hora máxima de pedido es obligatoria.');
        }

        // Find role_id based on role name
        $roleId = null;
        if (!empty($row['tipo_de_usuario'])) {
            $role = Role::where('name', $row['tipo_de_usuario'])->first();
            if (!$role) {
                throw new \Exception('El tipo de usuario especificado no existe.');
            }
            $roleId = $role->id;
        }

        // Find permissions_id based on permission name
        $permissionId = null;
        if (!empty($row['tipo_de_convenio'])) {
            $permission = Permission::where('name', $row['tipo_de_convenio'])->first();
            if (!$permission) {
                throw new \Exception('El tipo de convenio especificado no existe.');
            }
            $permissionId = $permission->id;
        }

        // Determine active value
        $active = $this->convertToBoolean($row['activo'] ?? true);

        return [
            'title' => $row['titulo'],
            'description' => $this->cleanDescriptionField($row['descripcion']),
            'publication_date' => $publicationDate,
            'role_id' => $roleId,
            'permissions_id' => $permissionId,
            'max_order_date' => $maxOrderDate,
            'active' => $active,
        ];
    }

    /**
     * Handle company associations for a menu
     *
     * @param Menu $menu
     * @param Collection $row
     */
    private function handleCompanyAssociations(Menu $menu, Collection $row): void
    {
        // Check if empresas_asociadas column exists
        if (!isset($row['empresas_asociadas'])) {
            return;
        }

        // Parse registration numbers
        $registrationNumbers = $this->parseCompanyRegistrationNumbers($row['empresas_asociadas']);

        if (empty($registrationNumbers)) {
            // Clear any existing associations if no companies specified
            $menu->companies()->detach();
            return;
        }

        // Find companies by registration number
        $companyIds = \App\Models\Company::whereIn('registration_number', $registrationNumbers)
            ->pluck('id')
            ->toArray();

        if (!empty($companyIds)) {
            // Sync company associations
            $menu->companies()->sync($companyIds);
        } else {
            // Clear associations if no valid companies found
            $menu->companies()->detach();
        }
    }

    /**
     * Validation rules
     * 
     * @return array
     */
    public function rules(): array
    {
        return $this->getValidationRules();
    }

    /**
     * Custom validation messages
     * 
     * @return array
     */
    public function customValidationMessages(): array
    {
        return $this->getValidationMessages();
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

            // Get current process and its existing errors
            $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
            $existingErrors = $importProcess->error_log ?? [];

            // Add new error to existing array
            $existingErrors[] = $error;

            // Update error_log in ImportProcess
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

        // Get current process and its existing errors
        $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];

        // Add new error to existing array
        $existingErrors[] = $error;

        // Update error_log in ImportProcess
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
