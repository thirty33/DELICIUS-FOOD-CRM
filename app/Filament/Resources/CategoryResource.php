<?php

namespace App\Filament\Resources;

use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Resources\CategoryResource\RelationManagers;
use App\Models\Category;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TimePicker;
use Filament\Forms;
use App\Enums\Subcategory;
use App\Exports\CategoryExport;
use App\Exports\CategoryLineDataExport;
use App\Exports\CategoryLineTemplateExport;
use App\Imports\CategoryLineImport;
use App\Jobs\BulkDeleteCategories;
use App\Models\CategoryLine;
use App\Models\ExportProcess;
use App\Models\ImportProcess;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Almacén');
    }

    public static function getLabel(): ?string
    {
        return __('Categoría');
    }

    public static function getNavigationLabel(): string
    {
        return __('Categorías');
    }

    protected static ?string $navigationIcon = 'bx-category-alt';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->autofocus()
                    ->required()
                    ->minLength(2)
                    ->maxLength(200)
                    ->unique(static::getModel(), 'name', ignoreRecord: true)
                    ->label(__('Nombre'))
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(__('Descripción'))
                    ->rows(2)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('Activo'))
                    ->default(true)
                    ->inline(false),
                Forms\Components\Select::make('subcategories')
                    ->relationship(
                        name: 'subcategories',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn($query) => $query->distinct()
                    )
                    ->multiple()
                    ->label(__('Subcategorías'))
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable()
                    ->description(fn(Category $category) => $category->description),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('Activo'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('subcategories.name')
                    ->label(__('Subcategorías'))
                    ->badge(),
            ])
            ->filters([])
            ->headerActions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('import_categories')
                        ->label('Importar categorías')
                        ->color('info')
                        ->icon('tabler-file-upload')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->visibility('private')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('categories-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {

                                $importProcess = \App\Models\ImportProcess::create([
                                    'type' => ImportProcess::TYPE_CATEGORIES,
                                    'status' => ImportProcess::STATUS_QUEUED,
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(
                                    new \App\Imports\CategoryImport($importProcess->id),
                                    $data['file'],
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                CategoryResource::makeNotification(
                                    'Categorías importadas',
                                    'El proceso de importación finalizará en breve',
                                )->send();
                            } catch (\Exception $e) {

                                Log::error('Error en importación de categorías', [
                                    'message' => $e->getMessage() . ' ' . $e->getLine(),
                                ]);

                                CategoryResource::makeNotification(
                                    'Error',
                                    'El proceso ha fallado',
                                )->send();
                            }
                        }),
                    Tables\Actions\Action::make('download_categories_template')
                        ->label('Bajar plantilla de categorías')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            return Excel::download(
                                new \App\Exports\CategoryTemplateExport(),
                                'template_importacion_categorias.xlsx'
                            );
                        }),
                    Tables\Actions\Action::make('import_category_lines')
                        ->label('Importar reglas de despacho')
                        ->color('info')
                        ->icon('tabler-file-upload')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->disk('s3')
                                ->visibility('private')
                                ->maxSize(10240)
                                ->maxFiles(1)
                                ->directory('category-lines-imports')
                                ->visibility('public')
                                ->label('Archivo')
                                ->required(),
                        ])
                        ->action(function (array $data) {
                            try {
                                $importProcess = ImportProcess::create([
                                    'type' => ImportProcess::TYPE_DISPATCH_LINES,
                                    'status' => ImportProcess::STATUS_QUEUED,
                                    'file_url' => $data['file'],
                                ]);

                                Excel::import(
                                    new CategoryLineImport($importProcess->id),
                                    $data['file'],
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                self::makeNotification(
                                    'Reglas de despacho importadas',
                                    'El proceso de importación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {
                                Log::error('error en importación de reglas de despacho', [
                                    'message' => $e->getMessage() . ' ' . $e->getLine(),
                                ]);

                                self::makeNotification(
                                    'Error',
                                    'El proceso ha fallado',
                                    'danger'
                                )->send();
                            }
                        }),

                    // Acción de descargar template
                    Tables\Actions\Action::make('download_category_lines_template')
                        ->label('Bajar plantilla de reglas de despacho')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function () {
                            return Excel::download(
                                new CategoryLineTemplateExport(),
                                'template_importacion_reglas_despacho.xlsx'
                            );
                        }),
                ])->dropdownWidth(MaxWidth::ExtraSmall)
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->action(function (Category $record) {
                        try {
                            Log::info('Iniciando proceso de eliminación de categoría', [
                                'category_id' => $record->id,
                                'category_name' => $record->name
                            ]);

                            // Crear array con el ID de la categoría a eliminar
                            $categoryIdToDelete = [$record->id];

                            // Dispatch el job para eliminar la categoría en segundo plano
                            BulkDeleteCategories::dispatch($categoryIdToDelete);

                            self::makeNotification(
                                'Eliminación en proceso',
                                'La categoría será eliminada en segundo plano.'
                            )->send();

                            Log::info('Job de eliminación de categoría enviado a la cola', [
                                'category_id' => $record->id
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error al preparar eliminación de categoría', [
                                'category_id' => $record->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);

                            self::makeNotification(
                                'Error',
                                'Ha ocurrido un error al preparar la eliminación de la categoría: ' . $e->getMessage(),
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
                                Log::info('Iniciando proceso de eliminación masiva de categorías', [
                                    'total_records' => $records->count(),
                                    'category_ids' => $records->pluck('id')->toArray()
                                ]);

                                // Obtener los IDs de las categorías a eliminar
                                $categoryIdsToDelete = $records->pluck('id')->toArray();

                                // Dispatch el job para eliminar las categorías en segundo plano
                                if (!empty($categoryIdsToDelete)) {
                                    BulkDeleteCategories::dispatch($categoryIdsToDelete);

                                    CategoryResource::makeNotification(
                                        'Eliminación en proceso',
                                        'Las categorías seleccionadas serán eliminadas en segundo plano.'
                                    )->send();

                                    Log::info('Job de eliminación masiva de categorías enviado a la cola', [
                                        'category_ids' => $categoryIdsToDelete
                                    ]);
                                } else {
                                    CategoryResource::makeNotification(
                                        'Información',
                                        'No hay categorías para eliminar.'
                                    )->send();
                                }
                            } catch (\Exception $e) {
                                Log::error('Error al preparar eliminación de categorías', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                CategoryResource::makeNotification(
                                    'Error',
                                    'Ha ocurrido un error al preparar la eliminación de categorías: ' . $e->getMessage(),
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('export_categories')
                        ->label('Exportar categorías')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('success')
                        ->action(function (Collection $records) {
                            try {
                                // Crear el proceso de exportación
                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_CATEGORIES,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                // Generar el nombre del archivo
                                $fileName = "exports/categories/categorias_export_{$exportProcess->id}_" . time() . '.xlsx';

                                // Realizar la exportación
                                Excel::store(
                                    new CategoryExport($records, $exportProcess->id),
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                // Actualizar la URL del archivo
                                $fileUrl = Storage::disk('s3')->url($fileName);
                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                CategoryResource::makeNotification(
                                    'Exportación iniciada',
                                    'El proceso de exportación finalizará en breve'
                                )->send();
                            } catch (\Exception $e) {

                                ExportErrorHandler::handle($e, $exportProcess->id ?? 0, 'bulk_action');

                                Log::error('Error en exportación de categorías', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                CategoryResource::makeNotification(
                                    'Error',
                                    'Error al iniciar la exportación',
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('export_category_lines')
                        ->label('Exportar reglas de despacho')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('success')
                        ->action(function (Collection $records) {
                            try {

                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::TYPE_DISPATCH_LINES,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-'
                                ]);

                                $fileName = "exports/category-lines/reglas_despacho_export_{$exportProcess->id}_" . time() . '.xlsx';

                                // Obtener las CategoryLines pero solo los IDs para evitar el problema con SQS
                                $categoryIds = $records->pluck('id')->toArray();
                                $categoryLinesIds = CategoryLine::whereIn('category_id', $categoryIds)
                                    ->pluck('id');

                                if ($categoryLinesIds->isEmpty()) {
                                    self::makeNotification(
                                        'Sin datos',
                                        'Las categorías seleccionadas no tienen reglas de despacho',
                                        'warning'
                                    )->send();
                                    return;
                                }

                                if ($categoryLinesIds->isEmpty()) {
                                    self::makeNotification(
                                        'Sin datos',
                                        'Las categorías seleccionadas no tienen reglas de despacho',
                                        'warning'
                                    )->send();
                                    return;
                                }

                                Excel::store(
                                    new CategoryLineDataExport($categoryLinesIds, $exportProcess->id),
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

                                ExportErrorHandler::handle($e, $exportProcess->id ?? 0, 'bulk_export_category_lines');

                                self::makeNotification(
                                    'Error',
                                    'Error al iniciar la exportación',
                                    'danger'
                                )->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion()
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->emptyStateDescription(__('No hay categorías disponibles'));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CategoryLinesRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
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
