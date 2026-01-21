<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MenuResource\Pages;
use App\Filament\Resources\MenuResource\RelationManagers;
use App\Models\Menu;
use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use App\Enums\RoleName;
use App\Models\Permission;
use Closure;
use Filament\Forms\Get;
use Illuminate\Validation\Rule;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;
use App\Jobs\BulkDeleteMenus;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Filament\Support\Enums\MaxWidth;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\MenusImport;
use App\Imports\CategoryMenuImport;
use App\Exports\MenuTemplateExport;
use App\Exports\MenuDataExport;
use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Exports\CategoryMenuDataExport;
use App\Exports\CategoryMenuTemplateExport;
use App\Models\CategoryMenu;
use App\Models\ImportProcess;
use App\Models\ExportProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\MenuCloneService;
use App\Services\BestSellingProductsCategoryMenuService;

class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static ?string $navigationIcon = 'bxs-food-menu';

    protected static ?int $navigationSort = 70;

    public static function getNavigationGroup(): ?string
    {
        return __('Almacén');
    }

    public static function getLabel(): ?string
    {
        return __('Menú');
    }

    public static function getNavigationLabel(): string
    {
        return __('Menus');
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label(__('Título'))
                            ->unique(ignoreRecord: true)
                            ->required()
                            ->maxLength(255)
                            ->columns(1),
                        DatePicker::make('publication_date')
                            ->label(__('Fecha de despacho'))
                            ->required()
                            ->columns(1)
                            ->native(false)
                            ->displayFormat('M d, Y'),
                        DateTimePicker::make('max_order_date')
                            ->label(__('Fecha y hora máxima de pedido'))
                            ->required()
                            ->columns(1)
                            ->seconds(true)
                            ->format('Y-m-d H:i:s')
                            ->native(false),
                        Forms\Components\Select::make('rol')
                            ->relationship('rol', 'name')
                            ->label(__('Tipo de usuario'))
                            ->required()
                            ->live(),
                        Forms\Components\Select::make('permission')
                            ->relationship('permission', 'name')
                            ->label(__('Tipo de Convenio'))
                            ->required(function (Get $get) {
                                $agreementRoleId = Role::where('name', RoleName::AGREEMENT)->first()->id;
                                $cafeRoleId = Role::where('name', RoleName::CAFE)->first()->id;

                                $selectedRole = $get('rol');

                                return (in_array($selectedRole, [$agreementRoleId, $cafeRoleId])) && empty($value);
                            }),
                        Toggle::make('active')
                            ->label(__('Activo'))
                            ->default(true)
                            ->inline(false),
                        Forms\Components\Textarea::make('description')
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Descripción'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('publication_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(__('Título'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('publication_date')
                    ->label(__('Fecha de despacho'))
                    ->sortable()
                    ->date('d/m/Y')
                    ->searchable(),
                Tables\Columns\TextColumn::make('max_order_date')
                    ->label(__('Fecha y hora máxima de pedido'))
                    ->sortable()
                    ->dateTime('d/m/Y H:i:s')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rol.name')
                    ->label(__('Tipo de usuario'))
                    ->badge(),
                Tables\Columns\TextColumn::make('permission.name')
                    ->label(__('Tipo de Convenio'))
                    ->badge(),
                Tables\Columns\ToggleColumn::make('active')
                    ->label(__('Activo'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('companies.fantasy_name')
                    ->label(__('Empresas asociadas'))
                    ->badge()
                    ->separator(', ')
                    ->wrap(),
            ])
            ->filters([
                DateRangeFilter::make('publication_date')
                    ->label(__('Fecha de despacho')),
                DateRangeFilter::make('max_order_date')
                    ->label(__('Fecha y hora máxima de pedido')),
                Tables\Filters\SelectFilter::make('rol')
                    ->relationship('rol', 'name')
                    ->label(__('Tipo de usuario'))
                    ->options(Role::pluck('name', 'id')->toArray()),
                Tables\Filters\SelectFilter::make('permission')
                    ->relationship('permission', 'name')
                    ->label(__('Tipo de Convenio'))
                    ->options(Permission::pluck('name', 'id')->toArray()),
            ])
            ->headerActions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('import_menus')
                        ->label('Importar menús')
                        ->color('info')
                        ->icon('tabler-file-upload')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->visibility('private')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('menus-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {
                                $importProcess = ImportProcess::create([
                                    'type' => ImportProcess::TYPE_MENUS,
                                    'status' => ImportProcess::STATUS_QUEUED,
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(
                                    new MenusImport($importProcess->id),
                                    $data['file'],
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                self::makeNotification(
                                    'Menús importados',
                                    'El proceso de importación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error
                                ExportErrorHandler::handle(
                                    $e,
                                    $importProcess->id ?? 0,
                                    'import_menus_action',
                                    'ImportProcess'
                                );

                                self::makeNotification(
                                    'Error',
                                    'El proceso ha fallado',
                                    'danger'
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('download_menus_template')
                        ->label('Bajar plantilla de menús')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            try {
                                return Excel::download(
                                    new MenuTemplateExport(),
                                    'template_importacion_menus.xlsx'
                                );
                            } catch (\Exception $e) {
                                self::makeNotification(
                                    'Error',
                                    'Error al generar la plantilla de menús',
                                    'danger'
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('import_menu_categories')
                        ->label('Importar categorías de menú')
                        ->color('success')
                        ->icon('tabler-category')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->visibility('private')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('menu-categories-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {
                                $importProcess = ImportProcess::create([
                                    'type' => ImportProcess::TYPE_MENU_CATEGORIES,
                                    'status' => ImportProcess::STATUS_QUEUED,
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(
                                    new CategoryMenuImport($importProcess->id),
                                    $data['file'],
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                self::makeNotification(
                                    'Categorías de menú importadas',
                                    'El proceso de importación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error
                                ExportErrorHandler::handle(
                                    $e,
                                    $importProcess->id ?? 0,
                                    'import_menu_categories_action',
                                    'ImportProcess'
                                );

                                self::makeNotification(
                                    'Error',
                                    'El proceso ha fallado',
                                    'danger'
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('download_menu_categories_template') // Nuevo action
                        ->label('Bajar plantilla de categorías de menú')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            try {
                                return Excel::download(
                                    new CategoryMenuTemplateExport(), // Usar la nueva clase
                                    'template_importacion_categorias_menu.xlsx'
                                );
                            } catch (\Exception $e) {
                                self::makeNotification(
                                    'Error',
                                    'Error al generar la plantilla de categorías de menú',
                                    'danger'
                                )->send();
                            }
                        }),
                ])->dropdownWidth(MaxWidth::ExtraSmall)
            ])
            ->actions([
                self::getCloneAction(),
                self::getBestSellingProductsAction(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->action(function (Menu $record) {
                        try {
                            Log::info('Iniciando proceso de eliminación de menú', [
                                'menu_id' => $record->id,
                                'menu_title' => $record->title
                            ]);

                            // Crear array con el ID del menú a eliminar
                            $menuIdToDelete = [$record->id];

                            // Dispatch el job para eliminar el menú en segundo plano
                            BulkDeleteMenus::dispatch($menuIdToDelete);

                            self::makeNotification(
                                'Eliminación en proceso',
                                'El menú será eliminado en segundo plano.'
                            )->send();

                            Log::info('Job de eliminación de menú enviado a la cola', [
                                'menu_id' => $record->id
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error al preparar eliminación de menú', [
                                'menu_id' => $record->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);

                            self::makeNotification(
                                'Error',
                                'Ha ocurrido un error al preparar la eliminación del menú: ' . $e->getMessage(),
                                'danger'
                            )->send();

                            // Re-lanzar la excepción para que Filament la maneje
                            throw $e;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    BulkAction::make('bulk-delete')
                        ->label(__('Eliminar seleccionados'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            // Obtener IDs de los registros seleccionados
                            $menuIds = $records->pluck('id')->toArray();

                            // Enviar el trabajo a la cola con los IDs
                            dispatch(new BulkDeleteMenus($menuIds));

                            // Mostrar notificación al usuario
                            Notification::make()
                                ->title(__('Eliminación en proceso'))
                                ->body(__('Los menús seleccionados se están eliminando en segundo plano.'))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('export_menus')
                        ->label('Exportar menús')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('success')
                        ->action(function (Collection $records) {
                            try {
                                $menuIds = $records->pluck('id')->toArray();
                                $menuIds = Menu::whereIn('id', $menuIds)
                                    ->pluck('id');

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_MENUS,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                $fileName = "exports/menus/menus_export_{$exportProcess->id}_" . time() . '.xlsx';

                                Excel::store(
                                    new MenuDataExport($menuIds, $exportProcess->id),
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                $fileUrl = Storage::disk('s3')->url($fileName);
                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                self::makeNotification(
                                    'Exportación iniciada',
                                    'El proceso de exportación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                // Usar ExportErrorHandler para registrar el error de manera consistente
                                ExportErrorHandler::handle(
                                    $e,
                                    $exportProcess->id ?? 0,
                                    'bulk_export_menus'
                                );

                                self::makeNotification(
                                    'Error',
                                    'Error al iniciar la exportación',
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('export_menu_categories')
                        ->label('Exportar categorías de menú')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('success')
                        ->action(function (Collection $records) {
                            try {
                                // Get the unique Menu IDs from the selected records
                                $menuIds = $records->pluck('id')->toArray();

                                // Find CategoryMenu entries related to these Menu IDs
                                $categoryMenuIds = CategoryMenu::whereIn('menu_id', $menuIds)
                                    ->pluck('id');

                                // Create an export process
                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_MENU_CATEGORIES,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                // Generate filename with timestamp and export process ID
                                $fileName = "exports/menu-categories/menu_categories_export_{$exportProcess->id}_" . time() . '.xlsx';

                                // Store the export file
                                Excel::store(
                                    new CategoryMenuDataExport($categoryMenuIds, $exportProcess->id),
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                // Get the file URL and update the export process
                                $fileUrl = Storage::disk('s3')->url($fileName);
                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                // Send success notification
                                self::makeNotification(
                                    'Exportación iniciada',
                                    'El proceso de exportación de categorías de menú finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                // Handle and log any errors during export
                                ExportErrorHandler::handle(
                                    $e,
                                    $exportProcess->id ?? 0,
                                    'bulk_export_menu_categories'
                                );

                                // Send error notification
                                self::makeNotification(
                                    'Error',
                                    'Error al iniciar la exportación de categorías de menú',
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    self::getBestSellingProductsBulkAction(),
                ])->dropdownWidth(MaxWidth::ExtraSmall),
            ])
        ;
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CategoriesRelationManager::class,
            RelationManagers\CompaniesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenus::route('/'),
            'create' => Pages\CreateMenu::route('/create'),
            'edit' => Pages\EditMenu::route('/{record}/edit'),
        ];
    }

    public static function getCloneActionForm(): array
    {
        return [
            Forms\Components\TextInput::make('title')
                ->label(__('Título'))
                ->required()
                ->maxLength(255),
            DatePicker::make('publication_date')
                ->label(__('Fecha de despacho'))
                ->required()
                ->native(false)
                ->displayFormat('M d, Y'),
            DateTimePicker::make('max_order_date')
                ->label(__('Fecha y hora máxima de pedido'))
                ->required()
                ->seconds(true)
                ->format('Y-m-d H:i:s')
                ->native(false),
            Forms\Components\Select::make('companies')
                ->label(__('Empresas asociadas'))
                ->options(\App\Models\Company::where('active', true)->pluck('name', 'id'))
                ->multiple()
                ->searchable(),
        ];
    }

    public static function executeCloneAction(array $data, Menu $record): mixed
    {
        try {
            $newMenu = MenuCloneService::cloneMenu($record, $data);

            if (!empty($data['companies'])) {
                $newMenu->companies()->sync($data['companies']);
            }

            Notification::make()
                ->title('Menú clonado exitosamente')
                ->body("Se ha creado el menú '{$data['title']}' basado en '{$record->title}'")
                ->success()
                ->send();

            return redirect()->to(self::getUrl('edit', ['record' => $newMenu]));

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error al clonar menú')
                ->body('Ha ocurrido un error: ' . $e->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    public static function getCloneAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('clone_menu')
            ->label('Clonar')
            ->color('primary')
            ->icon('heroicon-o-document-duplicate')
            ->form(self::getCloneActionForm())
            ->action(fn (array $data, Menu $record) => self::executeCloneAction($data, $record));
    }

    private static function makeNotification(string $title, string $body, string $color = 'success'): Notification
    {
        return Notification::make()
            ->color($color)
            ->title($title)
            ->body($body);
    }

    /**
     * Get the form schema for best-selling products action.
     */
    public static function getBestSellingProductsActionForm(): array
    {
        return [
            DatePicker::make('start_date')
                ->label(__('Fecha inicio'))
                ->required()
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(now()->subMonth()->startOfMonth()),
            DatePicker::make('end_date')
                ->label(__('Fecha fin'))
                ->required()
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(now()->subMonth()->endOfMonth()),
            Forms\Components\TextInput::make('limit')
                ->label(__('Cantidad de productos'))
                ->numeric()
                ->required()
                ->default(10)
                ->minValue(1)
                ->maxValue(50),
        ];
    }

    /**
     * Execute the best-selling products action for a collection of menus.
     */
    public static function executeBestSellingProductsAction(array $data, Collection $menus): void
    {
        try {
            $service = app(BestSellingProductsCategoryMenuService::class);

            $menusWithRole = Menu::whereIn('id', $menus->pluck('id'))
                ->with('rol')
                ->get();

            $dispatchedCount = $service->processMenus(
                $menusWithRole,
                $data['start_date'],
                $data['end_date'],
                (int) $data['limit']
            );

            if ($dispatchedCount > 0) {
                self::makeNotification(
                    'Proceso iniciado',
                    "Se han programado {$dispatchedCount} menús para agregar productos más vendidos."
                )->send();
            } else {
                self::makeNotification(
                    'Sin menús para procesar',
                    'No se encontraron menús con rol Café entre los seleccionados.',
                    'warning'
                )->send();
            }
        } catch (\Exception $e) {
            Log::error('Error al ejecutar acción de productos más vendidos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            self::makeNotification(
                'Error',
                'Ha ocurrido un error: ' . $e->getMessage(),
                'danger'
            )->send();
        }
    }

    /**
     * Get the row action for best-selling products.
     */
    public static function getBestSellingProductsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('best_selling_products')
            ->label(__('Agregar más vendidos'))
            ->color('warning')
            ->icon('heroicon-o-fire')
            ->form(self::getBestSellingProductsActionForm())
            ->action(fn (array $data, Menu $record) => self::executeBestSellingProductsAction(
                $data,
                collect([$record])
            ));
    }

    /**
     * Get the bulk action for best-selling products.
     */
    public static function getBestSellingProductsBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('best_selling_products_bulk')
            ->label(__('Agregar más vendidos'))
            ->color('warning')
            ->icon('heroicon-o-fire')
            ->form(self::getBestSellingProductsActionForm())
            ->action(fn (array $data, Collection $records) => self::executeBestSellingProductsAction(
                $data,
                $records
            ))
            ->deselectRecordsAfterCompletion();
    }
}
