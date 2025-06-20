<?php

namespace App\Filament\Resources;

use App\Enums\RoleName;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\Role;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;
use Filament\Forms\Components\Toggle;
use Closure;
use Filament\Forms\Get;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\UserImport;
use App\Exports\UserDataExport;
use App\Exports\UserTemplateExport;
use App\Jobs\BulkDeleteUsers;
use App\Models\ImportProcess;
use App\Models\ExportProcess;
use App\Classes\ErrorManagment\ExportErrorHandler;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Jobs\SendCredentialsEmails;
use Filament\Support\Enums\ActionSize;
use Filament\Tables\Actions\ActionGroup as ActionsActionGroup;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Seguridad');
    }

    public static function getLabel(): ?string
    {
        return __('Usuario');
    }

    public static function getNavigationLabel(): string
    {
        return __('Usuarios');
    }

    public static function form(Form $form): Form
    {
        return $form
            // Desactivar validaciones HTML5 nativas del navegador
            ->extraAttributes(['novalidate' => true])
            ->schema([
                TextInput::make('name')
                    ->autofocus()
                    ->required()
                    ->maxLength(200)
                    ->label(__('Nombre')),
                TextInput::make('email')
                    ->email()
                    ->required(fn(Get $get): bool => empty($get('nickname')))
                    ->maxLength(200)
                    ->unique(static::getModel(), 'email', ignoreRecord: true)
                    ->label(__('Correo electrónico'))
                    // Evitar validación nativa del navegador
                    ->extraAttributes(['required' => false]),
                TextInput::make('nickname')
                    ->maxLength(200)
                    ->required(fn(Get $get): bool => empty($get('email')))
                    ->unique(static::getModel(), 'nickname', ignoreRecord: true)
                    ->label(__('Nickname'))
                    // Evitar validación nativa del navegador
                    ->extraAttributes(['required' => false]),
                Forms\Components\Select::make('roles')
                    ->relationship('roles', 'name')
                    // ->multiple()
                    ->label(__('Tipo de usuario'))
                    ->required()
                    ->live(),
                Forms\Components\Select::make('permissions')
                    ->relationship('permissions', 'name')
                    ->label(__('Tipo de Convenio'))
                    ->required(function (Get $get) {
                        $agreementRoleId = Role::where('name', RoleName::AGREEMENT)->first()->id;
                        $cafeRoleId = Role::where('name', RoleName::CAFE)->first()->id;
                        $selectedRoles = (array)$get('roles');

                        return in_array($agreementRoleId, $selectedRoles) ||
                            in_array($cafeRoleId, $selectedRoles);
                    })
                    ->live(),
                Forms\Components\Select::make('company_id')
                    ->relationship('company', 'name')
                    ->required()
                    ->label(__('Compañia'))
                    ->columns(1)
                    ->searchable()
                    ->live(),
                Forms\Components\Select::make('branch_id')
                    ->label(__('Sucursal'))
                    ->relationship(
                        name: 'company.branches',
                        titleAttribute: 'fantasy_name',
                        modifyQueryUsing: fn(Builder $query, callable $get) =>
                        $query->when(
                            $get('company_id'),
                            fn($query, $companyId) => $query->where('company_id', $companyId)
                        )
                    )
                    ->required()
                    ->searchable(),
                Forms\Components\Section::make(__('Información de contraseña'))
                    ->description(__('La contraseña debe cumplir con los siguientes requisitos de seguridad:
                    • Mínimo 8 caracteres y máximo 25
                    • Al menos una letra minúscula (a-z)
                    • Al menos una letra mayúscula (A-Z)
                    • Al menos un número (0-9)
                    • Al menos un carácter especial (@$!%*?&#)'))
                    ->schema([
                        // Campo para mostrar la contraseña en texto plano
                        TextInput::make('plain_password')
                            ->label(__('Contraseña actual (texto plano)'))
                            ->disabled() // No se puede editar directamente
                            ->dehydrated(false) // No se envía en el formulario
                            ->visible(function ($record, $livewire) {
                                // Mostrar si hay un registro existente con plain_password
                                // o si se ha ingresado una nueva contraseña
                                return ($record && !empty($record->plain_password)) ||
                                    (!empty($livewire->data['plain_password']));
                            })
                            ->afterStateHydrated(function (TextInput $component, $record) {
                                if ($record && $record->plain_password) {
                                    $component->state($record->plain_password);
                                }
                            }),

                        // Añadimos un campo oculto para guardar la contraseña en texto plano
                        Forms\Components\Hidden::make('plain_password_input')
                            ->dehydrated(true),

                        TextInput::make('password')
                            ->password()
                            // Descripción de los requisitos de la contraseña
                            ->helperText(__('Debe tener entre 8 y 25 caracteres, incluir mayúsculas, minúsculas, números y caracteres especiales (@$!%*?&#)'))
                            // Modificado para guardar también en plain_password
                            ->live() // Hacer el campo live para poder reaccionar a cambios
                            ->afterStateUpdated(function (TextInput $component, $state, $livewire) {
                                // Si se ingresó una nueva contraseña, actualizar el campo de visualización
                                if (!empty($state)) {
                                    \Log::info('Password field updated - afterStateUpdated', [
                                        'state_length' => strlen($state),
                                        'is_create' => !isset($livewire->record)
                                    ]);

                                    // También actualizamos el campo oculto con la contraseña original
                                    $livewire->data['plain_password_input'] = $state;

                                    // Para mostrar en tiempo real
                                    $livewire->data['plain_password'] = $state;
                                }
                            })
                            ->dehydrateStateUsing(function ($state, $record = null) {
                                // Log al inicio de dehydrateStateUsing
                                \Log::info('Password dehydrateStateUsing - start', [
                                    'state' => $state,
                                    'record_id' => $record ? $record->id : null,
                                    'is_creating' => $record === null
                                ]);

                                // Si hay un valor nuevo de contraseña
                                if (filled($state)) {
                                    // Solo guardamos plain_password si $record no es null
                                    if ($record !== null) {
                                        \Log::info('Saving plain_password to DB directly', [
                                            'user_id' => $record->id,
                                            'plain_password_length' => strlen($state)
                                        ]);

                                        try {
                                            // Guardamos directamente en la base de datos para evitar que pase por los mutators del modelo
                                            \DB::table('users')
                                                ->where('id', $record->id)
                                                ->update(['plain_password' => $state]);

                                            \Log::info('Successfully saved plain_password');
                                        } catch (\Exception $e) {
                                            \Log::error('Error saving plain_password', [
                                                'error' => $e->getMessage(),
                                                'trace' => $e->getTraceAsString()
                                            ]);
                                        }
                                    } else {
                                        \Log::info('Record is null, not saving plain_password yet');
                                    }

                                    // Devolver el hash para password
                                    $hashedPassword = Hash::make($state);
                                    \Log::info('Returning hashed password', [
                                        'hash_length' => strlen($hashedPassword)
                                    ]);
                                    return $hashedPassword;
                                }

                                \Log::info('No new password provided, returning null');
                                return null; // No modificar la contraseña si está vacío
                            })
                            ->dehydrated(fn($state) => filled($state))
                            ->required(fn(string $context): bool => $context === 'create')
                            ->confirmed()
                            ->minLength(8)
                            ->maxLength(25)
                            ->rule(function () {
                                return function (string $attribute, $value, Closure $fail) {
                                    // Check lowercase
                                    if (!preg_match('/[a-z]/', $value)) {
                                        $fail(__('La contraseña debe contener al menos una letra minúscula.'));
                                    }

                                    // Check uppercase
                                    if (!preg_match('/[A-Z]/', $value)) {
                                        $fail(__('La contraseña debe contener al menos una letra mayúscula.'));
                                    }

                                    // Check number
                                    if (!preg_match('/[0-9]/', $value)) {
                                        $fail(__('La contraseña debe contener al menos un número.'));
                                    }

                                    // Check special character
                                    if (!preg_match('/[@$!%*?&#]/', $value)) {
                                        $fail(__('La contraseña debe contener al menos un carácter especial (@$!%*?&#).'));
                                    }
                                };
                            })
                            ->label(__('Contraseña')),
                        TextInput::make('password_confirmation')
                            ->password()
                            ->label(__('Confirmar contraseña')),
                    ]),
                Toggle::make('allow_late_orders')
                    ->label(__('Validar fecha y reglas de despacho'))
                    ->inline(false),
                Toggle::make('validate_min_price')
                    ->label(__('Validar precio mímimo'))
                    ->inline(false),
                Toggle::make('validate_subcategory_rules')
                    ->label(__('Validar reglas de subcategoría'))
                    ->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('nickname', 'like', "%{$search}%");
                    })
                    ->description(fn(User $user) => $user->email ?: $user->nickname),
                Tables\Columns\TextColumn::make('nickname')
                    ->label(__('Nickname'))
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label(__('Tipo de usuario'))
                    ->badge(),
                Tables\Columns\TextColumn::make('permissions.name')
                    ->label(__('Tipo de Convenio'))
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('Empresa'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('branch.fantasy_name')
                    ->label(__('Sucursal'))
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->label(__('Roles'))
                    ->options(Role::pluck('name', 'id')->toArray()),
                Tables\Filters\SelectFilter::make('permissions')
                    ->relationship('permissions', 'name')
                    ->label(__('Tipo de Convenio')),
                DateRangeFilter::make('created_at')
                    ->label(__('Fecha de creación')),
            ])
            ->headerActions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('import_users')
                        ->label('Importar usuarios')
                        ->color('info')
                        ->icon('tabler-file-upload')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->visibility('private')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('users-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {
                                $importProcess = ImportProcess::create([
                                    'type' => ImportProcess::TYPE_USERS,
                                    'status' => ImportProcess::STATUS_QUEUED,
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(
                                    new UserImport($importProcess->id),
                                    $data['file'],
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                Notification::make()
                                    ->title('Usuarios importados')
                                    ->body('El proceso de importación finalizará en breve')
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error
                                ExportErrorHandler::handle(
                                    $e,
                                    $importProcess->id ?? 0,
                                    'import_users_action',
                                    'ImportProcess'
                                );

                                Notification::make()
                                    ->title('Error')
                                    ->body('El proceso ha fallado')
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Tables\Actions\Action::make('download_users_template')
                        ->label('Bajar plantilla de usuarios')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            try {
                                return Excel::download(
                                    new UserTemplateExport(),
                                    'template_importacion_usuarios.xlsx'
                                );
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error
                                ExportErrorHandler::handle(
                                    $e,
                                    0,
                                    'download_users_template_action'
                                );

                                Notification::make()
                                    ->title('Error')
                                    ->body('Error al generar la plantilla de usuarios')
                                    ->danger()
                                    ->send();
                            }
                        }),
                ])->dropdownWidth(MaxWidth::ExtraSmall)
            ])
            ->actions([
                ActionsActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->hidden(fn(User $record): bool => $record->id === auth()->id())
                        ->before(function (User $record) {
                            if ($record->id === auth()->id()) {
                                Notification::make()
                                    ->title('Error')
                                    ->body('No puedes eliminar tu propio usuario.')
                                    ->danger()
                                    ->send();
    
                                return false;
                            }
                        })
                        ->action(function (User $record) {
                            try {
                                Log::info('Iniciando proceso de eliminación de usuario', [
                                    'user_id' => $record->id,
                                    'user_name' => $record->name
                                ]);
    
                                // Crear array con el ID del usuario a eliminar
                                $userIdToDelete = [$record->id];
    
                                // Dispatch el job para eliminar el usuario en segundo plano
                                BulkDeleteUsers::dispatch($userIdToDelete);
    
                                Notification::make()
                                    ->title('Eliminación en proceso')
                                    ->body('El usuario será eliminado en segundo plano.')
                                    ->success()
                                    ->send();
    
                                Log::info('Job de eliminación de usuario enviado a la cola', [
                                    'user_id' => $record->id
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Error al preparar eliminación de usuario', [
                                    'user_id' => $record->id,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);
    
                                Notification::make()
                                    ->title('Error')
                                    ->body('Ha ocurrido un error al preparar la eliminación del usuario: ' . $e->getMessage())
                                    ->danger()
                                    ->send();
    
                                // Re-lanzar la excepción para que Filament la maneje
                                throw $e;
                            }
                        }),
                    Tables\Actions\Action::make('send_credentials')
                        ->label('Enviar credenciales')
                        ->icon('heroicon-o-envelope')
                        ->color('info')
                        ->action(function (User $record) {
                            try {
                                // Crear array con el ID del usuario
                                $userIdToSend = [$record->id];
    
                                // Dispatch el job para enviar el correo en segundo plano
                                SendCredentialsEmails::dispatch($userIdToSend);
    
                                Notification::make()
                                    ->title('Envío en proceso')
                                    ->body('Las credenciales serán enviadas en segundo plano.')
                                    ->success()
                                    ->send();
    
                                Log::info('Job de envío de credenciales enviado a la cola', [
                                    'user_id' => $record->id
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Error al preparar envío de credenciales', [
                                    'user_id' => $record->id,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);
    
                                Notification::make()
                                    ->title('Error')
                                    ->body('Ha ocurrido un error al preparar el envío de credenciales: ' . $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                ])
                ->label('Acciones')
                ->icon('heroicon-m-ellipsis-vertical')
                ->size(ActionSize::Small)
                ->color('primary')
                ->button()
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            try {
                                \Log::info('Iniciando proceso de eliminación masiva de usuarios', [
                                    'total_records' => $records->count(),
                                    'user_ids' => $records->pluck('id')->toArray()
                                ]);

                                $currentUserId = auth()->id();
                                \Log::info('Usuario actual', ['current_user_id' => $currentUserId]);

                                // Filtrar el usuario actual de los registros a eliminar
                                $userIdsToDelete = $records
                                    ->filter(function ($record) use ($currentUserId) {
                                        return $record->id !== $currentUserId;
                                    })
                                    ->pluck('id')
                                    ->toArray();

                                \Log::info('Registros después de filtrar', [
                                    'filtered_count' => count($userIdsToDelete),
                                    'filtered_ids' => $userIdsToDelete
                                ]);

                                // Si se intentó eliminar al usuario actual, mostrar notificación
                                if ($records->count() !== count($userIdsToDelete)) {
                                    \Log::info('Se intentó eliminar al usuario actual');

                                    Notification::make()
                                        ->title('Advertencia')
                                        ->body('No puedes eliminar tu propio usuario. Se procesarán los demás usuarios seleccionados.')
                                        ->warning()
                                        ->send();
                                }

                                // Dispatch el job para eliminar los usuarios en segundo plano
                                if (!empty($userIdsToDelete)) {
                                    BulkDeleteUsers::dispatch($userIdsToDelete);

                                    Notification::make()
                                        ->title('Eliminación en proceso')
                                        ->body('Los usuarios seleccionados serán eliminados en segundo plano.')
                                        ->success()
                                        ->send();

                                    \Log::info('Job de eliminación masiva de usuarios enviado a la cola', [
                                        'user_ids' => $userIdsToDelete
                                    ]);
                                } else {
                                    Notification::make()
                                        ->title('Información')
                                        ->body('No hay usuarios para eliminar.')
                                        ->info()
                                        ->send();
                                }
                            } catch (\Exception $e) {
                                \Log::error('Error al preparar eliminación de usuarios', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                Notification::make()
                                    ->title('Error')
                                    ->body('Ha ocurrido un error al preparar la eliminación de usuarios: ' . $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Tables\Actions\BulkAction::make('export_users')
                        ->label('Exportar usuarios')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('success')
                        ->action(function (Collection $records) {
                            try {
                                $userIds = $records->pluck('id');

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_USERS,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                $fileName = "exports/users/usuarios_export_{$exportProcess->id}_" . time() . '.xlsx';

                                Excel::store(
                                    new UserDataExport($userIds, $exportProcess->id),
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                $fileUrl = Storage::disk('s3')->url($fileName);
                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                Notification::make()
                                    ->title('Exportación iniciada')
                                    ->body('El proceso de exportación finalizará en breve')
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error de manera consistente
                                ExportErrorHandler::handle(
                                    $e,
                                    $exportProcess->id ?? 0,
                                    'bulk_export_users'
                                );

                                Notification::make()
                                    ->title('Error')
                                    ->body('Error al iniciar la exportación')
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('send_credentials_bulk')
                        ->label('Enviar credenciales')
                        ->icon('heroicon-o-envelope')
                        ->color('info')
                        ->action(function (Collection $records) {
                            try {
                                Log::info('Iniciando proceso de envío masivo de credenciales', [
                                    'total_records' => $records->count(),
                                    'user_ids' => $records->pluck('id')->toArray()
                                ]);

                                // Obtener los IDs de los usuarios seleccionados
                                $userIds = $records->pluck('id')->toArray();

                                // Dispatch el job para enviar los correos en segundo plano
                                if (!empty($userIds)) {
                                    SendCredentialsEmails::dispatch($userIds);

                                    Notification::make()
                                        ->title('Envío en proceso')
                                        ->body('Las credenciales de los usuarios seleccionados serán enviadas en segundo plano.')
                                        ->success()
                                        ->send();

                                    Log::info('Job de envío masivo de credenciales enviado a la cola', [
                                        'user_ids' => $userIds
                                    ]);
                                } else {
                                    Notification::make()
                                        ->title('Información')
                                        ->body('No hay usuarios seleccionados para enviar credenciales.')
                                        ->info()
                                        ->send();
                                }
                            } catch (\Exception $e) {
                                Log::error('Error al preparar envío masivo de credenciales', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                Notification::make()
                                    ->title('Error')
                                    ->body('Ha ocurrido un error al preparar el envío masivo de credenciales: ' . $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CategoryUserLinesRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
