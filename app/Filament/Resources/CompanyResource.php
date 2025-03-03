<?php

namespace App\Filament\Resources;

use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Exports\CompanyBranchesExport;
use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource\RelationManagers;
use App\Imports\CompaniesImport;
use App\Models\Company;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CompanyTemplateExport;
use App\Models\ExportProcess;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Jobs\BulkDeleteCompanies;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'carbon-location-company-filled';

    public static function getNavigationGroup(): ?string
    {
        return __('Administración');
    }

    public static function getLabel(): ?string
    {
        return __('Empresa');
    }

    public static function getNavigationLabel(): string
    {
        return __('Empresas');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('logo')
                    ->label(__('Logo'))
                    ->image()
                    ->maxSize(4096)
                    ->placeholder(__('Logo de la empresa'))
                    ->columnSpanFull(),
                Forms\Components\Wizard::make([
                    Forms\Components\Wizard\Step::make(__('Datos principales'))
                        ->schema([
                            Forms\Components\Grid::make()
                                ->schema([
                                    Forms\Components\TextInput::make('company_code')
                                        ->required()
                                        ->minLength(2)
                                        ->maxLength(50)
                                        ->label(__('Código'))
                                        ->unique(static::getModel(), 'company_code', ignoreRecord: true)
                                        ->columns(1),
                                    Forms\Components\TextInput::make('tax_id')
                                        ->required()
                                        ->label(__('RUT'))
                                        ->unique(static::getModel(), 'tax_id', ignoreRecord: true)
                                        ->nullable()
                                        ->columns(1),
                                    Forms\Components\TextInput::make('name')
                                        ->autofocus()
                                        ->required()
                                        ->minLength(2)
                                        ->maxLength(200)
                                        ->unique(static::getModel(), 'name', ignoreRecord: true)
                                        ->label(__('Razón social'))
                                        ->columns(1),
                                    Forms\Components\TextInput::make('business_activity')
                                        ->label(__('Giro'))
                                        ->nullable()
                                        ->columns(1),
                                    Forms\Components\TextInput::make('fantasy_name')
                                        ->autofocus()
                                        ->required()
                                        ->minLength(2)
                                        ->maxLength(200)
                                        ->unique(static::getModel(), 'fantasy_name', ignoreRecord: true)
                                        ->label(__('Nombre de fantasía'))
                                        ->columns(1),
                                    Forms\Components\TextInput::make('registration_number')
                                        ->required()
                                        ->minLength(2)
                                        ->maxLength(200)
                                        ->label(__('Número de registro'))
                                        ->default(function () {
                                            // Solo genera el número aleatorio si estamos en la página de creación
                                            if (request()->routeIs('*.create')) {
                                                return 'REG-' . strtoupper(substr(md5(uniqid()), 0, 8));
                                            }
                                            return null;
                                        })
                                        ->disabled(function () {
                                            // Deshabilita el campo si estamos editando
                                            return !request()->routeIs('*.create');
                                        })
                                        ->dehydrated()
                                        ->columns(1),
                                    Forms\Components\TextInput::make('acronym')
                                        ->label(__('Sigla'))
                                        ->nullable()
                                        ->columns(1),
                                ])->columns(3)
                        ]),
                    Forms\Components\Wizard\Step::make(__('Datos de contacto'))
                        ->schema([
                            Forms\Components\Grid::make()
                                ->schema([
                                    Forms\Components\TextInput::make('address')
                                        ->required()
                                        ->minLength(2)
                                        ->maxLength(200)
                                        ->label(__('Dirección'))
                                        ->columns(1),
                                    Forms\Components\TextInput::make('shipping_address')
                                        ->label(__('Dirección de Despacho'))
                                        ->nullable()
                                        ->columns(1),
                                    Forms\Components\TextInput::make('email')
                                        ->required()
                                        ->minLength(2)
                                        ->maxLength(200)
                                        ->label(__('Email'))
                                        ->columns(1),
                                    Forms\Components\TextInput::make('phone_number')
                                        ->required()
                                        ->minLength(2)
                                        ->maxLength(200)
                                        ->label(__('Número de teléfono'))
                                        ->columns(1),
                                    Forms\Components\TextInput::make('website')
                                        ->minLength(2)
                                        ->maxLength(200)
                                        ->label(__('Website'))
                                        ->columns(1),
                                    Forms\Components\TextInput::make('contact_name')
                                        ->label(__('Nombre contacto'))
                                        ->nullable()
                                        ->columns(1),
                                    Forms\Components\TextInput::make('contact_last_name')
                                        ->label(__('Apellido de contacto'))
                                        ->nullable()
                                        ->columns(1),
                                    Forms\Components\TextInput::make('contact_phone_number')
                                        ->label(__('Número de contacto'))
                                        ->nullable()
                                        ->columns(1),
                                ])->columns(3)
                        ]),
                    Forms\Components\Wizard\Step::make(__('Otros datos'))
                        ->schema([
                            Forms\Components\Grid::make()
                                ->schema([
                                    Forms\Components\TextInput::make('state_region')
                                        ->label(__('Estado/Región'))
                                        ->nullable()
                                        ->columns(1),
                                    Forms\Components\TextInput::make('city')
                                        ->label(__('Ciudad'))
                                        ->nullable()
                                        ->columns(1),
                                    Forms\Components\TextInput::make('country')
                                        ->label(__('País'))
                                        ->nullable()
                                        ->columns(1),
                                    Forms\Components\Select::make('price_list_id')
                                        ->relationship('priceList', 'name')
                                        ->label(__('Lista de precio'))
                                        ->searchable()
                                        ->columns(1),
                                    Forms\Components\TextInput::make('payment_condition')
                                        ->label(__('Condición de pago'))
                                        ->nullable()
                                        ->columns(1),
                                    Checkbox::make('active')
                                        ->label(__('Activo'))
                                        ->columns(2),
                                    Forms\Components\Textarea::make('description')
                                        ->required()
                                        ->minLength(2)
                                        ->maxLength(200)
                                        ->label(__('Descripción'))
                                        ->columnSpanFull(),
                                ])->columns(3),
                        ])
                ])
                    ->columnSpanFull()
                    ->persistStepInQueryString('company-wizard-step')
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label(__('Imagen')),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('registration_number')
                    ->label(__('Número de registro'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Actualizado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\ToggleColumn::make('active')
                    ->label(__('Activo'))
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('Importar empresas')
                        ->color('info')
                        ->icon('tabler-file-upload')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('companies-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {

                                $importProcess = \App\Models\ImportProcess::create([
                                    'type' => 'empresas',
                                    'status' => 'en cola',
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(new CompaniesImport($importProcess->id), $data['file'], 's3', \Maatwebsite\Excel\Excel::XLSX);
                                CompanyResource::makeNotification(
                                    'Usuarios importados',
                                    'El proceso de importación finalizará en breve',
                                )->send();
                            } catch (\Exception $e) {

                                Log::error('error on import', [
                                    'message' => $e->getMessage() . ' ' . $e->getLine(),
                                ]);

                                CompanyResource::makeNotification(
                                    'Error',
                                    'El proceso ha falladado',
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('download_template')
                        ->label('Bajar plantilla de empresas')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            return Excel::download(
                                new CompanyTemplateExport(),
                                'template_importacion_empresas.xlsx'
                            );
                        }),
                    Tables\Actions\Action::make('Importar sucursales')
                        ->color('info')
                        ->icon('tabler-file-upload')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('branches-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {
                                $importProcess = \App\Models\ImportProcess::create([
                                    'type' => \App\Models\ImportProcess::TYPE_BRANCHES,
                                    'status' => \App\Models\ImportProcess::STATUS_QUEUED,
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(
                                    new \App\Imports\CompanyBranchesImport($importProcess->id),
                                    $data['file'],
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                CompanyResource::makeNotification(
                                    'Sucursales importadas',
                                    'El proceso de importación finalizará en breve',
                                )->send();
                            } catch (\Exception $e) {
                                Log::error('error on import', [
                                    'message' => $e->getMessage() . ' ' . $e->getLine(),
                                ]);

                                CompanyResource::makeNotification(
                                    'Error',
                                    'El proceso ha fallado',
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('download_template_branches')
                        ->label('Bajar plantilla de sucursales')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            return Excel::download(
                                new CompanyBranchesExport(),
                                'template_importacion_empresas.xlsx'
                            );
                        }),
                ])->dropdownWidth(MaxWidth::ExtraSmall)
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->action(function (Company $record) {
                        try {
                            Log::info('Iniciando proceso de eliminación de empresa', [
                                'company_id' => $record->id,
                                'company_name' => $record->name
                            ]);

                            // Crear array con el ID de la empresa a eliminar
                            $companyIdToDelete = [$record->id];

                            // Dispatch el job para eliminar la empresa en segundo plano
                            BulkDeleteCompanies::dispatch($companyIdToDelete);

                            self::makeNotification(
                                'Eliminación en proceso',
                                'La empresa será eliminada en segundo plano.'
                            )->send();

                            Log::info('Job de eliminación de empresa enviado a la cola', [
                                'company_id' => $record->id
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error al preparar eliminación de empresa', [
                                'company_id' => $record->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);

                            self::makeNotification(
                                'Error',
                                'Ha ocurrido un error al preparar la eliminación de la empresa: ' . $e->getMessage(),
                                'danger'
                            )->send();

                            // Re-lanzar la excepción para que Filament la maneje
                            throw $e;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            try {
                                Log::info('Iniciando proceso de eliminación masiva de empresas', [
                                    'total_records' => $records->count(),
                                    'company_ids' => $records->pluck('id')->toArray()
                                ]);

                                // Obtener los IDs de las empresas a eliminar
                                $companyIdsToDelete = $records->pluck('id')->toArray();

                                // Dispatch el job para eliminar las empresas en segundo plano
                                if (!empty($companyIdsToDelete)) {
                                    BulkDeleteCompanies::dispatch($companyIdsToDelete);

                                    CompanyResource::makeNotification(
                                        'Eliminación en proceso',
                                        'Las empresas seleccionadas serán eliminadas en segundo plano.'
                                    )->send();

                                    Log::info('Job de eliminación masiva de empresas enviado a la cola', [
                                        'company_ids' => $companyIdsToDelete
                                    ]);
                                } else {
                                    CompanyResource::makeNotification(
                                        'Información',
                                        'No hay empresas para eliminar.'
                                    )->send();
                                }
                            } catch (\Exception $e) {
                                Log::error('Error al preparar eliminación de empresas', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                CompanyResource::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al preparar la eliminación de empresas: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('Exportar empresas')
                        ->icon('tabler-file-download')
                        ->color('info')
                        ->action(function (Collection $records) {
                            try {

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_COMPANIES,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                $fileName = "exports/companies/empresas_export_{$exportProcess->id}_" . time() . '.xlsx';

                                Excel::store(
                                    new \App\Exports\CompaniesDataExport($records, $exportProcess->id),
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                $fileUrl = Storage::disk('s3')->url($fileName);

                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                CompanyResource::makeNotification(
                                    'Exportación iniciada',
                                    'El proceso de exportación finalizará en breve',
                                )->send();
                            } catch (\Exception $e) {

                                Log::error('Error en exportación de empresas', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                CompanyResource::makeNotification(
                                    'Error',
                                    'Error al iniciar la exportación',
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('export_branches')
                        ->label('Exportar sucursales')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('success')
                        ->action(function (Collection $records) {
                            try {
                                // Crear el proceso de exportación
                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_BRANCHES,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                // Generar el nombre del archivo
                                $fileName = "exports/branches/sucursales_export_{$exportProcess->id}_" . time() . '.xlsx';

                                // Realizar la exportación
                                Excel::store(
                                    new \App\Exports\CompanyBranchesDataExport($records, $exportProcess->id),
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                // Actualizar la URL del archivo
                                $fileUrl = Storage::disk('s3')->url($fileName);
                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                CompanyResource::makeNotification(
                                    'Exportación iniciada',
                                    'El proceso de exportación de sucursales finalizará en breve',
                                )->send();
                            } catch (\Exception $e) {

                                ExportErrorHandler::handle($e, $exportProcess->id ?? 0, 'bulk_action');

                                Log::error('Error en exportación de sucursales', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                CompanyResource::makeNotification(
                                    'Error',
                                    'Error al iniciar la exportación de sucursales',
                                )->send();
                            }
                        })
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->emptyStateDescription(__('No hay empresas creadas'));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BranchesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }

    private static function makeNotification(string $title, string $body, string $color = 'success'): Notification
    {
        return Notification::make()
            ->color($color)
            ->title($title)
            ->body($body);
    }
}
