<?php

namespace App\Filament\Resources;

use App\Contracts\ImportServiceInterface;
use App\Contracts\PlatedDishRepositoryInterface;
use App\Filament\Resources\PlatedDishResource\Pages;
use App\Filament\Resources\PlatedDishResource\RelationManagers;
use App\Imports\PlatedDishIngredientsImport;
use App\Models\ExportProcess;
use App\Models\ImportProcess;
use App\Models\PlatedDish;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PlatedDishResource extends Resource
{
    protected static ?string $model = PlatedDish::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): ?string
    {
        return __('Producción');
    }

    public static function getLabel(): ?string
    {
        return __('Emplatado');
    }

    public static function getNavigationLabel(): string
    {
        return __('Emplatados');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->relationship(
                        'product',
                        'name',
                        fn (Builder $query) => $query->where('active', true)->orderBy('code')
                    )
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->code} - {$record->name}")
                    ->label(__('Producto'))
                    ->searchable(['code', 'name'])
                    ->preload()
                    ->required()
                    ->helperText(__('Selecciona el producto principal que representa este emplatado')),

                Forms\Components\Toggle::make('is_active')
                    ->label(__('Activo'))
                    ->default(true)
                    ->inline(false),

                Forms\Components\Toggle::make('is_horeca')
                    ->label(__('ES HORECA'))
                    ->default(false)
                    ->inline(false)
                    ->helperText(__('Indica si este emplatado es para clientes del canal HORECA'))
                    ->live(),

                Forms\Components\Select::make('related_product_id')
                    ->label(__('Producto Relacionado'))
                    ->options(function (Forms\Get $get, ?PlatedDish $record, \App\Contracts\PlatedDishRepositoryInterface $repository) {
                        $isHoreca = (bool) $get('is_horeca');
                        $excludeProductId = $record?->product_id;

                        return $repository->getRelatedProductOptions($isHoreca, $excludeProductId);
                    })
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText(function (Forms\Get $get) {
                        $isHoreca = (bool) $get('is_horeca');
                        return $isHoreca
                            ? __('Selecciona el producto INDIVIDUAL relacionado (solo productos NO HORECA con ingredientes)')
                            : __('Selecciona el producto HORECA relacionado (solo productos HORECA con ingredientes)');
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->defaultSort('product.code', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('product.code')
                    ->label(__('Código'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('product.name')
                    ->label(__('Producto'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->weight('bold')
                    ->description(fn (PlatedDish $record): string => $record->product->category->name ?? ''),

                Tables\Columns\TextColumn::make('ingredients_count')
                    ->label(__('Ingredientes'))
                    ->counts('ingredients')
                    ->sortable()
                    ->badge()
                    ->color('success')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('Activo'))
                    ->boolean()
                    ->sortable()
                    ->toggleable()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_horeca')
                    ->label(__('HORECA'))
                    ->boolean()
                    ->sortable()
                    ->toggleable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado'))
                    ->date('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Actualizado'))
                    ->date('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->searchable()
            ->filters([
                Tables\Filters\SelectFilter::make('product.category_id')
                    ->relationship('product.category', 'name')
                    ->label(__('Categoría'))
                    ->multiple()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('Activo'))
                    ->placeholder(__('Todos'))
                    ->trueLabel(__('Solo activos'))
                    ->falseLabel(__('Solo inactivos')),

                Tables\Filters\TernaryFilter::make('is_horeca')
                    ->label(__('HORECA'))
                    ->placeholder(__('Todos'))
                    ->trueLabel(__('Solo HORECA'))
                    ->falseLabel(__('Solo no HORECA')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('import_plated_dish_ingredients')
                    ->label(__('Importar Ingredientes de Emplatados'))
                    ->color('info')
                    ->icon('tabler-file-upload')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->disk('s3')
                            ->visibility('private')
                            ->maxSize(10240)
                            ->maxFiles(1)
                            ->directory('plated-dish-imports')
                            ->label(__('Archivo'))
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel'
                            ])
                            ->required(),
                    ])
                    ->action(function (
                        array $data,
                        ImportServiceInterface $importService,
                        PlatedDishRepositoryInterface $repository
                    ) {
                        try {
                            $importProcess = $importService->importWithRepository(
                                importerClass: PlatedDishIngredientsImport::class,
                                filePath: $data['file'],
                                importType: ImportProcess::TYPE_PLATED_DISH_INGREDIENTS,
                                repository: $repository,
                                disk: 's3'
                            );

                            Notification::make()
                                ->title(__('Importación iniciada'))
                                ->body(__('El proceso de importación finalizará en breve'))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error'))
                                ->body(__('El proceso ha fallado'))
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('download_plated_dish_ingredients_template')
                    ->label(__('Bajar plantilla de ingredientes de emplatados'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->action(function (\App\Services\TemplateDownloadService $templateService) {
                        return $templateService->download(
                            \App\Exports\PlatedDishIngredientsTemplateExport::class,
                            'template_importacion_ingredientes_emplatados.xlsx'
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(__('Editar')),
                Tables\Actions\DeleteAction::make()
                    ->label(__('Eliminar')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('export_data')
                        ->label(__('Exportar Datos'))
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->action(function (Collection $records, \App\Services\ExportService $exportService) {
                            try {
                                if ($records->isEmpty()) {
                                    Notification::make()
                                        ->title(__('Sin registros'))
                                        ->body(__('No hay registros seleccionados para exportar'))
                                        ->warning()
                                        ->send();
                                    return;
                                }

                                $platedDishIds = $records->pluck('id');
                                $fileName = 'plated-dish-exports/export_ingredientes_emplatados_' . now()->format('Ymd_His') . '.xlsx';

                                $exportProcess = $exportService->export(
                                    \App\Exports\PlatedDishIngredientsDataExport::class,
                                    $platedDishIds,
                                    ExportProcess::TYPE_PLATED_DISH_INGREDIENTS,
                                    $fileName,
                                    [],
                                    's3'
                                );

                                Notification::make()
                                    ->title(__('Exportación iniciada'))
                                    ->body(__('Se están exportando :count registros. El proceso finalizará en breve.', ['count' => $records->count()]))
                                    ->success()
                                    ->send();

                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title(__('Error'))
                                    ->body(__('Ha ocurrido un error al iniciar la exportación: ') . $e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->label(__('Eliminar seleccionados')),
                ]),
            ])
            ->emptyStateHeading(__('Sin emplatados'))
            ->emptyStateDescription(__('Comienza creando tu primer producto emplatado.'))
            ->emptyStateIcon('heroicon-o-cube-transparent');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\IngredientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlatedDishes::route('/'),
            'create' => Pages\CreatePlatedDish::route('/create'),
            'edit' => Pages\EditPlatedDish::route('/{record}/edit'),
        ];
    }
}