<?php

namespace App\Imports;

use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Permission;
use App\Models\ImportProcess;
use App\Classes\ErrorManagment\ExportErrorHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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

class UserImport implements
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
        'nombre' => 'name',
        'correo_electronico' => 'email',
        'tipo_de_usuario' => 'roles',
        'tipo_de_convenio' => 'permissions',
        'compania' => 'company_registration_number',
        'sucursal' => 'branch_code',
        'validar_fecha_y_reglas_de_despacho' => 'allow_late_orders',
        'validar_precio_minimo' => 'validate_min_price',
        'validar_reglas_de_subcategoria' => 'validate_subcategory_rules'
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    public function chunkSize(): int
    {
        return 10;
    }

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_PROCESSING]);

                Log::info('Iniciando importación de usuarios', ['process_id' => $this->importProcessId]);
            },

            AfterImport::class => function (AfterImport $event) {
                $importProcess = ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update([
                        'status' => ImportProcess::STATUS_PROCESSED
                    ]);
                }

                Log::info('Finalizada importación de usuarios', ['process_id' => $this->importProcessId]);
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
    public function rules(): array
    {
        return [
            '*.nombre' => ['required', 'string', 'max:200'],
            '*.correo_electronico' => ['required', 'email', 'max:200'],
            '*.tipo_de_usuario' => ['required', 'string', 'exists:roles,name'],
            '*.tipo_de_convenio' => ['nullable', 'string', 'exists:permissions,name'],
            '*.compania' => ['required', 'string', 'exists:companies,registration_number'],
            '*.sucursal' => ['required', 'string', 'exists:branches,branch_code'],
            '*.validar_fecha_y_reglas_de_despacho' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0,si,no,yes,no'],
            '*.validar_precio_minimo' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0,si,no,yes,no'],
            '*.validar_reglas_de_subcategoria' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0,si,no,yes,no']
        ];
    }

    /**
     * Get custom validation messages
     * 
     * @return array
     */
    public function customValidationMessages(): array
    {
        return [
            '*.nombre.required' => 'El nombre es obligatorio.',
            '*.correo_electronico.required' => 'El correo electrónico es obligatorio.',
            '*.correo_electronico.email' => 'El correo electrónico debe ser válido.',
            '*.correo_electronico.unique' => 'El correo electrónico ya existe.',
            '*.tipo_de_usuario.required' => 'El tipo de usuario es obligatorio.',
            '*.tipo_de_usuario.exists' => 'El tipo de usuario no existe.',
            '*.tipo_de_convenio.exists' => 'El tipo de convenio no existe.',
            '*.compania.required' => 'La compañía es obligatoria.',
            '*.compania.exists' => 'La compañía no existe.',
            '*.sucursal.required' => 'La sucursal es obligatoria.',
            '*.sucursal.exists' => 'La sucursal no existe.',
        ];
    }

    /**
     * Process the imported collection
     * 
     * @param Collection $rows
     */
    public function collection(Collection $rows)
    {
        try {
            Log::info('UserImport procesando colección', [
                'count' => $rows->count()
            ]);
            
            // Log de los encabezados del Excel
            if ($rows->isNotEmpty()) {
                Log::info('Encabezados detectados en el Excel', [
                    'headers' => $rows->first()->keys()->toArray()
                ]);
            }

            foreach ($rows as $index => $row) {
                try {
                    Log::info('Procesando fila ' . ($index + 2), [
                        'raw_data' => $row->toArray()
                    ]);
                    
                    // Verificar existencia de usuario antes de procesar
                    $existingUser = User::where('email', $row['correo_electronico'])->first();
                    if ($existingUser) {
                        Log::info('Usuario existente encontrado', [
                            'user_id' => $existingUser->id,
                            'email' => $existingUser->email,
                            'current_data' => [
                                'name' => $existingUser->name,
                                'company_id' => $existingUser->company_id,
                                'branch_id' => $existingUser->branch_id,
                                'roles' => $existingUser->roles->pluck('name')->toArray(),
                                'permissions' => $existingUser->permissions->pluck('name')->toArray(),
                            ]
                        ]);
                    }

                    // Preparar datos del usuario
                    $userData = $this->prepareUserData($row);
                    
                    Log::info('Datos preparados para actualización/creación', [
                        'email' => $row['correo_electronico'],
                        'processed_data' => $userData,
                        'is_new_user' => !$existingUser
                    ]);

                    // Crear o actualizar usuario usando el correo electrónico como clave única
                    $user = User::updateOrCreate(
                        ['email' => $userData['email']],
                        $userData
                    );
                    
                    Log::info('Usuario después de updateOrCreate', [
                        'user_id' => $user->id,
                        'is_new' => $user->wasRecentlyCreated,
                        'updated_fields' => $user->getChanges()
                    ]);

                    // Limpiar roles y permisos existentes antes de asignar nuevos
                    if ($user->roles->isNotEmpty()) {
                        Log::info('Roles existentes antes de detach', [
                            'user_id' => $user->id,
                            'roles' => $user->roles->pluck('name')->toArray()
                        ]);
                    }
                    
                    if ($user->permissions->isNotEmpty()) {
                        Log::info('Permisos existentes antes de detach', [
                            'user_id' => $user->id,
                            'permissions' => $user->permissions->pluck('name')->toArray()
                        ]);
                    }
                    
                    $user->roles()->detach();
                    $user->permissions()->detach();

                    // Asignar roles y permisos
                    $this->assignRolesAndPermissions($user, $row);
                    
                    // Recargar el usuario para obtener los roles y permisos actualizados
                    $user->load('roles', 'permissions');
                    
                    Log::info('Roles y permisos asignados', [
                        'user_id' => $user->id,
                        'roles' => $user->roles->pluck('name')->toArray(),
                        'permissions' => $user->permissions->pluck('name')->toArray()
                    ]);

                    Log::info('Usuario creado/actualizado con éxito', [
                        'user_id' => $user->id,
                        'email' => $userData['email'],
                        'name' => $userData['name'],
                        'is_new' => $user->wasRecentlyCreated
                    ]);
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
                        'stack_trace' => $e->getTraceAsString(),
                        'data' => $row->toArray()
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

            Log::error('Error general en la importación de usuarios', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Prepare user data from a row
     * 
     * @param Collection $row
     * @return array
     */
    private function prepareUserData(Collection $row): array
    {
        Log::info('Preparando datos de usuario', [
            'email' => $row['correo_electronico'],
            'values' => $row->toArray()
        ]);
        
        // Encontrar compañía por número de registro
        $company = Company::where('registration_number', $row['compania'])->first();
        if (!$company) {
            Log::error('Compañía no encontrada', [
                'registration_number' => $row['compania']
            ]);
            throw new \Exception("Compañía con número de registro {$row['compania']} no encontrada.");
        }
        
        Log::info('Compañía encontrada', [
            'registration_number' => $row['compania'],
            'company_id' => $company->id,
            'company_name' => $company->name
        ]);

        // Encontrar sucursal por código de sucursal
        $branch = Branch::where('branch_code', $row['sucursal'])
            ->where('company_id', $company->id)
            ->first();
            
        if (!$branch) {
            Log::error('Sucursal no encontrada', [
                'branch_code' => $row['sucursal'],
                'company_id' => $company->id
            ]);
            throw new \Exception("Sucursal con código {$row['sucursal']} no encontrada para la compañía {$company->name}.");
        }
        
        Log::info('Sucursal encontrada', [
            'branch_code' => $row['sucursal'],
            'branch_id' => $branch->id,
            'branch_name' => $branch->fantasy_name
        ]);

        $existingUser = User::where('email', $row['correo_electronico'])->first();
        
        if ($existingUser) {
            Log::info('Usuario existente encontrado en prepareUserData', [
                'user_id' => $existingUser->id,
                'email' => $existingUser->email
            ]);
        }

        // Procesar valores booleanos
        $allowLateOrders = $this->convertToBoolean($row['validar_fecha_y_reglas_de_despacho'] ?? true);
        $validateMinPrice = $this->convertToBoolean($row['validar_precio_minimo'] ?? true);
        $validateSubcategoryRules = $this->convertToBoolean($row['validar_reglas_de_subcategoria'] ?? true);
        
        Log::info('Valores booleanos procesados', [
            'allow_late_orders' => $allowLateOrders,
            'raw_value' => $row['validar_fecha_y_reglas_de_despacho'] ?? 'null',
            'validate_min_price' => $validateMinPrice,
            'raw_value' => $row['validar_precio_minimo'] ?? 'null',
            'validate_subcategory_rules' => $validateSubcategoryRules,
            'raw_value' => $row['validar_reglas_de_subcategoria'] ?? 'null'
        ]);

        $userData = [
            'name' => $row['nombre'],
            'email' => $row['correo_electronico'],
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'allow_late_orders' => $allowLateOrders,
            'validate_min_price' => $validateMinPrice,
            'validate_subcategory_rules' => $validateSubcategoryRules
        ];

        // Solo agregar contraseña si el usuario no existe
        if (!$existingUser) {
            // Generar contraseña temporal solo para usuarios nuevos
            $temporaryPassword = bin2hex(random_bytes(8)); // 16 caracteres aleatorios
            $userData['password'] = Hash::make($temporaryPassword);
            
            Log::info('Contraseña temporal generada para nuevo usuario', [
                'email' => $row['correo_electronico'],
                'password_length' => strlen($temporaryPassword)
            ]);
        } else {
            Log::info('No se generó contraseña para usuario existente', [
                'email' => $row['correo_electronico']
            ]);
        }
        
        Log::info('Datos preparados para usuario', [
            'email' => $row['correo_electronico'],
            'user_data' => array_keys($userData),
            'password_included' => isset($userData['password'])
        ]);

        return $userData;
    }

    /**
     * Assign roles and permissions to user
     * 
     * @param User $user
     * @param Collection $row
     */
    private function assignRolesAndPermissions(User $user, Collection $row)
    {
        Log::info('Asignando roles y permisos', [
            'user_id' => $user->id,
            'role' => $row['tipo_de_usuario'],
            'permission' => $row['tipo_de_convenio'] ?? 'null'
        ]);
        
        // Asignar rol
        $role = Role::where('name', $row['tipo_de_usuario'])->first();
        if (!$role) {
            Log::error('Rol no encontrado', [
                'role_name' => $row['tipo_de_usuario']
            ]);
            throw new \Exception("Rol {$row['tipo_de_usuario']} no encontrado.");
        }
        
        Log::info('Rol encontrado', [
            'role_id' => $role->id,
            'role_name' => $role->name
        ]);
        
        $user->roles()->attach($role);
        Log::info('Rol asignado', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'role_name' => $role->name
        ]);

        // Asignar permiso si está presente
        if (!empty($row['tipo_de_convenio'])) {
            $permission = Permission::where('name', $row['tipo_de_convenio'])->first();
            if (!$permission) {
                Log::error('Permiso no encontrado', [
                    'permission_name' => $row['tipo_de_convenio']
                ]);
                throw new \Exception("Permiso {$row['tipo_de_convenio']} no encontrado.");
            }
            
            Log::info('Permiso encontrado', [
                'permission_id' => $permission->id,
                'permission_name' => $permission->name
            ]);
            
            $user->permissions()->attach($permission);
            Log::info('Permiso asignado', [
                'user_id' => $user->id,
                'permission_id' => $permission->id,
                'permission_name' => $permission->name
            ]);
        } else {
            Log::info('No se asignó permiso (no especificado)', [
                'user_id' => $user->id
            ]);
        }
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
            
            Log::warning('Detalle del fallo de validación', [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ]);

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

            Log::warning('Fallo en validación de importación de usuarios', [
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
        
        Log::error('Detalle completo del error', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

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

        Log::error('Error en importación de usuarios', [
            'import_process_id' => $this->importProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}