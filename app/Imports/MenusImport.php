<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\Role;
use App\Models\Permission;
use App\Models\ImportProcess;
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
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    // Formatos aceptados para fecha y hora máxima
    private $dateTimeFormats = [
        'd/m/Y H:i:s',  // Con segundos: 01/01/2025 14:30:00
        'd/m/Y H:i'     // Sin segundos: 01/01/2025 14:30
    ];

    public function chunkSize(): int
    {
        return 10; // Aumentado para mejor rendimiento
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
     * Get validation rules for import
     * 
     * @return array
     */
    private function getValidationRules(): array
    {
        return [
            '*.titulo' => ['required', 'string', 'min:2', 'max:255'],
            '*.descripcion' => ['required', 'string', 'min:2', 'max:200'],
            '*.fecha_de_despacho' => ['required', 'string'],
            '*.tipo_de_usuario' => ['required', 'string', 'exists:roles,name'],
            '*.tipo_de_convenio' => ['required', 'string', 'exists:permissions,name'],
            '*.fecha_hora_maxima_pedido' => ['required', 'string'],
            '*.activo' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0,si,no,yes,no']
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
            '*.descripcion.required' => 'La descripción es obligatoria.',
            '*.descripcion.min' => 'La descripción debe tener al menos 2 caracteres.',
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
                // Verificar que fecha_hora_maxima_pedido esté presente
                if (!isset($row['fecha_hora_maxima_pedido'])) {
                    $validator->errors()->add(
                        "{$index}.fecha_hora_maxima_pedido",
                        'La fecha y hora máxima de pedido es obligatoria.'
                    );
                }

                // Validar título único excepto para el registro actual
                // if (isset($row['titulo'])) {
                //     $exists = Menu::where('title', $row['titulo'])->exists();

                //     if ($exists) {
                //         $validator->errors()->add(
                //             "{$index}.titulo",
                //             'Ya existe un menú con este título.'
                //         );
                //     }
                // }

                // Validar la combinación única de fecha-rol-permiso-activo
                if (isset($row['fecha_de_despacho'], $row['tipo_de_usuario'], $row['tipo_de_convenio'])) {
                    try {

                        $dateValue = $row['fecha_de_despacho'];
                        if (substr($dateValue, 0, 1) === "'") {
                            $dateValue = substr($dateValue, 1);
                        }

                        $publicationDate = Carbon::createFromFormat('d/m/Y', $dateValue)->format('Y-m-d');
                        $active = $this->convertToBoolean($row['activo'] ?? true);

                        $role = Role::where('name', $row['tipo_de_usuario'])->first();
                        $permission = Permission::where('name', $row['tipo_de_convenio'])->first();

                        if ($role && $permission) {
                            $duplicate = Menu::where('publication_date', $publicationDate)
                                ->where('role_id', $role->id)
                                ->where('permissions_id', $permission->id)
                                ->where('active', $active)
                                ->exists();

                            if ($duplicate) {
                                $validator->errors()->add(
                                    "{$index}.combinacion",
                                    'Ya existe un menú con la misma combinación de Fecha de despacho, Tipo de usuario, Tipo de Convenio y estado Activo.'
                                );
                            }
                        }
                    } catch (\Exception $e) {
                        $validator->errors()->add(
                            "{$index}.fecha_formato",
                            'Error al validar la combinación única. Verifique el formato de la fecha de despacho (debe ser DD/MM/YYYY).'
                        );
                    }
                }

                // Validar formato de fecha de despacho
                if (!empty($row['fecha_de_despacho'])) {
                    try {
                        $dateValue = $row['fecha_de_despacho'];
                        if (substr($dateValue, 0, 1) === "'") {
                            $dateValue = substr($dateValue, 1);
                        }
                        Carbon::createFromFormat('d/m/Y', $dateValue);
                    } catch (\Exception $e) {
                        $validator->errors()->add(
                            "{$index}.fecha_de_despacho",
                            'El formato de la fecha de despacho debe ser DD/MM/YYYY.'
                        );
                    }
                }

                // Validar formato de fecha y hora máxima de pedido
                if (!empty($row['fecha_hora_maxima_pedido'])) {
                    $dateTime = $this->parseWithMultipleFormats($row['fecha_hora_maxima_pedido'], $this->dateTimeFormats);

                    if (!$dateTime) {
                        Log::warning('Error al validar fecha y hora máxima', [
                            'row' => $index,
                            'value' => $row['fecha_hora_maxima_pedido']
                        ]);
                        $validator->errors()->add(
                            "{$index}.fecha_hora_maxima_pedido",
                            'El formato de la fecha y hora máxima de pedido debe ser DD/MM/YYYY HH:MM:SS o DD/MM/YYYY HH:MM.'
                        );
                    }
                }
            }
        });
    }

    private function parseWithMultipleFormats(string $dateString, array $formats): ?Carbon
    {
        if (substr($dateString, 0, 1) === "'") {
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

            // Datos de muestra para debugging
            if ($rows->count() > 0) {
                Log::debug('Muestra de datos:', [
                    'primera_fila' => $rows->first()->toArray(),
                    'columnas' => array_keys($rows->first()->toArray())
                ]);
            }

            // Process each row
            foreach ($rows as $index => $row) {
                try {
                    // Verificar que fecha_hora_maxima_pedido esté presente
                    if (!isset($row['fecha_hora_maxima_pedido'])) {
                        throw new \Exception('La fecha y hora máxima de pedido es obligatoria.');
                    }

                    // Verificar que todos los campos requeridos estén presentes y no estén vacíos
                    $requiredFields = ['titulo', 'descripcion', 'fecha_de_despacho', 'tipo_de_usuario', 'tipo_de_convenio', 'fecha_hora_maxima_pedido'];
                    foreach ($requiredFields as $field) {
                        if (!isset($row[$field]) || empty($row[$field])) {
                            throw new \Exception("El campo {$field} es requerido y no puede estar vacío");
                        }
                    }

                    $menuData = $this->prepareMenuData($row);

                    // Use updateOrCreate con title como llave identificatoria
                    Menu::updateOrCreate(
                        ['title' => $menuData['title']],
                        $menuData
                    );

                    Log::info('Menú creado/actualizado con éxito: ' . $menuData['title']);
                } catch (\Exception $e) {
                    // Usar el formato estándar de error a través de ExportErrorHandler
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
            // Usar el formato estándar de error a través de ExportErrorHandler
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
        // Formatear fechas
        $publicationDate = null;
        if (!empty($row['fecha_de_despacho'])) {
            try {
                // Eliminar comilla simple al inicio si existe
                $dateValue = $row['fecha_de_despacho'];
                if (substr($dateValue, 0, 1) === "'") {
                    $dateValue = substr($dateValue, 1);
                }

                $publicationDate = Carbon::createFromFormat('d/m/Y', $dateValue)->format('Y-m-d');
            } catch (\Exception $e) {
                Log::error('Error al parsear fecha de despacho: ' . $e->getMessage(), [
                    'value' => $row['fecha_de_despacho']
                ]);
                throw new \Exception('El formato de la fecha de despacho es incorrecto. Debe ser DD/MM/YYYY.');
            }
        }

        $maxOrderDate = null;
        if (!empty($row['fecha_hora_maxima_pedido'])) {

            $dateTimeValue = $row['fecha_hora_maxima_pedido'];
            if (substr($dateTimeValue, 0, 1) === "'") {
                $dateTimeValue = substr($dateTimeValue, 1);
            }


            $dateTime = $this->parseWithMultipleFormats($dateTimeValue, $this->dateTimeFormats);

            if ($dateTime) {
                // Convertir a formato estándar Y-m-d H:i:s, asegurando que tenga segundos
                $maxOrderDate = $dateTime->format('Y-m-d H:i:s');
                Log::debug('Fecha y hora máxima parseada correctamente', [
                    'original' => $row['fecha_hora_maxima_pedido'],
                    'parseada' => $maxOrderDate
                ]);
            } else {
                Log::error('Error al parsear fecha y hora máxima de pedido', [
                    'value' => $row['fecha_hora_maxima_pedido']
                ]);
                throw new \Exception('El formato de la fecha y hora máxima de pedido es incorrecto. Debe ser DD/MM/YYYY HH:MM:SS o DD/MM/YYYY HH:MM.');
            }
        } else {
            throw new \Exception('La fecha y hora máxima de pedido es obligatoria.');
        }

        // Buscar role_id basado en el nombre del rol
        $roleId = null;
        if (!empty($row['tipo_de_usuario'])) {
            $role = Role::where('name', $row['tipo_de_usuario'])->first();
            if (!$role) {
                throw new \Exception('El tipo de usuario especificado no existe.');
            }
            $roleId = $role->id;
        }

        // Buscar permissions_id basado en el nombre del permiso
        $permissionId = null;
        if (!empty($row['tipo_de_convenio'])) {
            $permission = Permission::where('name', $row['tipo_de_convenio'])->first();
            if (!$permission) {
                throw new \Exception('El tipo de convenio especificado no existe.');
            }
            $permissionId = $permission->id;
        }

        // Determinar valor activo
        $active = $this->convertToBoolean($row['activo'] ?? true);

        return [
            'title' => $row['titulo'],
            'description' => $row['descripcion'],
            'publication_date' => $publicationDate,
            'role_id' => $roleId,
            'permissions_id' => $permissionId,
            'max_order_date' => $maxOrderDate,
            'active' => $active,
        ];
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
