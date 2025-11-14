<?php

namespace App\Filament\Resources;

use App\Enums\AdvanceOrderStatus;
use App\Exports\AdvanceOrderReportExport;
use App\Filament\Resources\AdvanceOrderResource\Pages;
use App\Filament\Resources\AdvanceOrderResource\RelationManagers;
use App\Forms\AdvanceOrderReportOptionsForm;
use App\Models\AdvanceOrder;
use App\Models\ExportProcess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class AdvanceOrderResource extends Resource
{
    protected static ?string $model = AdvanceOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Producción');
    }

    public static function getLabel(): ?string
    {
        return __('Orden de Producción');
    }

    public static function getNavigationLabel(): string
    {
        return __('Órdenes de Producción');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Órdenes de Producción');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Información de la Orden de Producción'))
                    ->schema([
                        Forms\Components\Grid::make()
                            ->columns(3)
                            ->schema([
                                Forms\Components\DateTimePicker::make('preparation_datetime')
                                    ->label(__('Fecha y Hora de Elaboración'))
                                    ->required()
                                    ->native(false)
                                    ->columnSpan(1),
                                Forms\Components\DatePicker::make('initial_dispatch_date')
                                    ->label(__('Fecha Inicial de Despacho'))
                                    ->required()
                                    ->native(false)
                                    ->beforeOrEqual('final_dispatch_date')
                                    ->validationMessages([
                                        'before_or_equal' => 'La fecha inicial de despacho debe ser anterior o igual a la fecha final de despacho.',
                                    ])
                                    ->columnSpan(1),
                                Forms\Components\DatePicker::make('final_dispatch_date')
                                    ->label(__('Fecha Final de Despacho'))
                                    ->required()
                                    ->native(false)
                                    ->afterOrEqual('initial_dispatch_date')
                                    ->validationMessages([
                                        'after_or_equal' => 'La fecha final de despacho debe ser posterior o igual a la fecha inicial de despacho.',
                                    ])
                                    ->columnSpan(1),
                            ]),
                        Forms\Components\Select::make('status')
                            ->label(__('Estado'))
                            ->required()
                            ->options([
                                AdvanceOrderStatus::PENDING->value => AdvanceOrderStatus::PENDING->label(),
                                AdvanceOrderStatus::CANCELLED->value => AdvanceOrderStatus::CANCELLED->label(),
                                AdvanceOrderStatus::EXECUTED->value => AdvanceOrderStatus::EXECUTED->label(),
                            ])
                            ->default(AdvanceOrderStatus::PENDING->value)
                            ->disabled()
                            ->dehydrated()
                            ->native(false),
                        Forms\Components\Toggle::make('use_products_in_orders')
                            ->label(__('Cargar productos de pedidos automáticamente'))
                            ->helperText(__('Al activar, se cargarán todos los productos de las órdenes cuya fecha de despacho esté dentro del rango de fechas de este adelanto.'))
                            ->default(false),
                        Forms\Components\Placeholder::make('production_areas_display')
                            ->label(__('Cuartos Productivos'))
                            ->content(function ($record) {
                                if (!$record || $record->productionAreas->isEmpty()) {
                                    return new \Illuminate\Support\HtmlString('<span class="text-gray-500">No hay cuartos productivos asociados</span>');
                                }

                                $tags = $record->productionAreas->map(function ($area) {
                                    return '<span class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-200 bg-gray-50 dark:text-gray-100 dark:ring-gray-700 dark:bg-gray-800">'
                                        . e($area->name) .
                                        '</span>';
                                })->join(' ');

                                return new \Illuminate\Support\HtmlString('<div class="flex flex-wrap gap-2">' . $tags . '</div>');
                            })
                            ->helperText(__('Estos son los cuartos productivos asociados a esta orden de producción.'))
                            ->columnSpanFull()
                            ->visible(fn ($context) => $context === 'edit'),
                        Forms\Components\Textarea::make('description')
                            ->label(__('Descripción'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Fecha Creación'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('initial_dispatch_date')
                    ->label(__('Rango de Despacho'))
                    ->state(function (\App\Models\AdvanceOrder $record): string {
                        $initial = $record->initial_dispatch_date->format('d/m/Y');
                        $final = $record->final_dispatch_date->format('d/m/Y');
                        return $initial === $final ? $initial : "{$initial} - {$final}";
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('preparation_datetime')
                    ->label(__('Fecha de Elaboración'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->formatStateUsing(fn (AdvanceOrderStatus $state): string => $state->label())
                    ->color(fn (AdvanceOrderStatus $state): string => $state->color())
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->label(__('Productos'))
                    ->counts('products')
                    ->sortable(),
            ])
            ->filters([
                DateRangeFilter::make('created_at')
                    ->label(__('Fecha de Creación'))
                    ->columnSpan(1),
                DateRangeFilter::make('preparation_datetime')
                    ->label(__('Fecha de Elaboración'))
                    ->columnSpan(1),
                DateRangeFilter::make('initial_dispatch_date')
                    ->label(__('Fecha de Despacho'))
                    ->columnSpan(1),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options([
                        AdvanceOrderStatus::PENDING->value => AdvanceOrderStatus::PENDING->label(),
                        AdvanceOrderStatus::CANCELLED->value => AdvanceOrderStatus::CANCELLED->label(),
                        AdvanceOrderStatus::EXECUTED->value => AdvanceOrderStatus::EXECUTED->label(),
                    ])
                    ->columnSpan(1),
                Tables\Filters\TernaryFilter::make('use_products_in_orders')
                    ->label(__('Usar en Pedidos'))
                    ->placeholder(__('Todos'))
                    ->trueLabel(__('Sí'))
                    ->falseLabel(__('No'))
                    ->native(false)
                    ->columnSpan(1),
            ], layout: Tables\Enums\FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (\App\Models\AdvanceOrder $record) =>
                        $record->status === AdvanceOrderStatus::CANCELLED
                    )
                    ->requiresConfirmation()
                    ->modalHeading(__('Eliminar Orden de Producción Cancelada'))
                    ->modalDescription(__('¿Está seguro de que desea eliminar esta orden de producción cancelada? Esta acción eliminará la orden y todos sus registros relacionados de forma permanente.'))
                    ->modalSubmitActionLabel(__('Sí, eliminar'))
                    ->before(function (\App\Models\AdvanceOrder $record) {
                        $repository = app(\App\Repositories\AdvanceOrderRepository::class);
                        $repository->deleteAdvanceOrder($record);
                    })
                    ->successNotificationTitle(__('Orden de producción eliminada exitosamente')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('generate_consolidated_report')
                        ->label('Generar reporte consolidado')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('success')
                        ->form(AdvanceOrderReportOptionsForm::getSchema())
                        ->action(function (Collection $records, array $data) {
                            try {
                                // Filter only EXECUTED advance orders
                                $executedRecords = $records->filter(function ($record) {
                                    return $record->status === \App\Enums\AdvanceOrderStatus::EXECUTED;
                                });

                                // Get advance order IDs from executed records only
                                $advanceOrderIds = $executedRecords->pluck('id')->toArray();

                                if (empty($advanceOrderIds)) {
                                    \Filament\Notifications\Notification::make()
                                        ->warning()
                                        ->title('Sin órdenes ejecutadas')
                                        ->body('Solo se pueden generar reportes de órdenes de producción ejecutadas. Por favor, selecciona al menos una OP con estado "Ejecutado".')
                                        ->send();
                                    return;
                                }

                                // Notify if some records were filtered out
                                $filteredCount = $records->count() - $executedRecords->count();
                                if ($filteredCount > 0) {
                                    \Filament\Notifications\Notification::make()
                                        ->info()
                                        ->title('Registros filtrados')
                                        ->body("Se excluyeron {$filteredCount} OP(s) que no están en estado ejecutado.")
                                        ->send();
                                }

                                // Generate description using repository
                                $repository = app(\App\Repositories\AdvanceOrderRepository::class);
                                $description = $repository->generateExportDescription($advanceOrderIds);

                                // Create export process (QUEUED status only)
                                $exportProcess = ExportProcess::create([
                                    'type' => ExportProcess::ORDER_CONSOLIDATED,
                                    'status' => ExportProcess::STATUS_QUEUED,
                                    'file_url' => '-',
                                    'description' => $description
                                ]);

                                // Generate filename
                                $idsString = implode('-', $advanceOrderIds);
                                $fileName = "exports/advance-orders/advance-orders-{$idsString}-{$exportProcess->id}-" . now()->format('Y-m-d-His') . '.xlsx';

                                // Create export instance with options from form
                                // The Export class will handle status updates (PROCESSING, PROCESSED, PROCESSED_WITH_ERRORS)
                                $export = new AdvanceOrderReportExport(
                                    $advanceOrderIds,
                                    $exportProcess->id,
                                    $data['show_excluded_companies'] ?? true,
                                    $data['show_all_adelantos'] ?? true,
                                    $data['show_total_elaborado'] ?? true,
                                    $data['show_sobrantes'] ?? true
                                );

                                // Store to S3 (queued automatically via ShouldQueue)
                                Excel::store(
                                    $export,
                                    $fileName,
                                    's3',
                                    \Maatwebsite\Excel\Excel::XLSX
                                );

                                // Get file URL for ExportProcess
                                $fileUrl = Storage::disk('s3')->url($fileName);
                                $exportProcess->update([
                                    'file_url' => $fileUrl
                                ]);

                                \Filament\Notifications\Notification::make()
                                    ->success()
                                    ->title('Exportación iniciada')
                                    ->body('El proceso de exportación finalizará en breve')
                                    ->send();

                            } catch (\Exception $e) {
                                Log::error('Error al iniciar la generación del reporte consolidado de OP', [
                                    'export_process_id' => $exportProcess->id ?? 0,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                \Filament\Notifications\Notification::make()
                                    ->danger()
                                    ->title('Error')
                                    ->body('Error al iniciar la exportación')
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ProductsRelationManager::class,
            RelationManagers\AssociatedOrdersRelationManager::class,
            RelationManagers\AssociatedOrderLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdvanceOrders::route('/'),
            'create' => Pages\CreateAdvanceOrder::route('/create'),
            'edit' => Pages\EditAdvanceOrder::route('/{record}/edit'),
        ];
    }

    public static function getHeaderExecuteAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('execute')
            ->label(__('Ejecutar'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Ejecutar Orden de Producción'))
            ->modalDescription(__('¿Está seguro de que desea ejecutar esta orden de producción? Esta acción creará una transacción de bodega y actualizará el inventario.'))
            ->modalSubmitActionLabel(__('Sí, ejecutar'))
            ->visible(fn (\App\Models\AdvanceOrder $record) => $record->status === AdvanceOrderStatus::PENDING)
            ->action(function (\App\Models\AdvanceOrder $record) {
                $record->refresh();

                if ($record->status !== AdvanceOrderStatus::PENDING) {
                    \Filament\Notifications\Notification::make()
                        ->warning()
                        ->title(__('Orden ya ejecutada'))
                        ->body(__('Esta orden de producción ya ha sido ejecutada previamente.'))
                        ->send();
                    return;
                }

                $record->update(['status' => AdvanceOrderStatus::EXECUTED]);
                event(new \App\Events\AdvanceOrderExecuted($record));

                \Filament\Notifications\Notification::make()
                    ->success()
                    ->title(__('Orden ejecutada'))
                    ->body(__('La orden de producción ha sido ejecutada exitosamente.'))
                    ->send();

                return redirect()->to(static::getUrl('edit', ['record' => $record]));
            });
    }

    public static function getHeaderCancelAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('cancel_production_order')
            ->label(__('Cancelar'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancelar Orden de Producción'))
            ->modalDescription(function (\App\Models\AdvanceOrder $record) {
                if ($record->status === AdvanceOrderStatus::PENDING) {
                    return __('¿Está seguro de que desea cancelar esta orden de producción pendiente?');
                }
                return __('¿Está seguro de que desea cancelar esta orden de producción? Esta acción cancelará la transacción de bodega y revertirá el inventario.');
            })
            ->modalSubmitActionLabel(__('Sí, cancelar'))
            ->visible(fn (\App\Models\AdvanceOrder $record) =>
                $record->status === AdvanceOrderStatus::EXECUTED ||
                $record->status === AdvanceOrderStatus::PENDING
            )
            ->action(function (\App\Models\AdvanceOrder $record) {
                $record->refresh();

                // Validate status
                if ($record->status === AdvanceOrderStatus::CANCELLED) {
                    \Filament\Notifications\Notification::make()
                        ->warning()
                        ->title(__('Ya cancelada'))
                        ->body(__('Esta orden de producción ya está cancelada.'))
                        ->send();
                    return;
                }

                if (!in_array($record->status, [AdvanceOrderStatus::PENDING, AdvanceOrderStatus::EXECUTED])) {
                    \Filament\Notifications\Notification::make()
                        ->warning()
                        ->title(__('Estado inválido'))
                        ->body(__('Esta orden no puede ser cancelada en su estado actual.'))
                        ->send();
                    return;
                }

                // For EXECUTED orders, check if can be cancelled
                if ($record->status === AdvanceOrderStatus::EXECUTED) {
                    $repository = app(\App\Repositories\AdvanceOrderRepository::class);
                    if (!$repository->canCancelAdvanceOrder($record)) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title(__('No se puede cancelar'))
                            ->body(__('Esta orden no puede ser cancelada porque existen otras órdenes posteriores con las mismas fechas de elaboración y despacho.'))
                            ->send();
                        return;
                    }
                }

                // Capture previous status BEFORE updating
                $previousStatus = $record->status;

                // Cancel the order
                $record->update(['status' => AdvanceOrderStatus::CANCELLED]);

                // Only fire event for EXECUTED orders (to revert inventory)
                if ($previousStatus === AdvanceOrderStatus::EXECUTED) {
                    event(new \App\Events\AdvanceOrderCancelled($record));
                    $message = __('La orden de producción ha sido cancelada exitosamente. El inventario ha sido revertido.');
                } else {
                    $message = __('La orden de producción pendiente ha sido cancelada exitosamente.');
                }

                \Filament\Notifications\Notification::make()
                    ->success()
                    ->title(__('Orden cancelada'))
                    ->body($message)
                    ->send();

                return redirect()->to(static::getUrl('edit', ['record' => $record]));
            });
    }

    public static function getHeaderDeleteAction(): \Filament\Actions\DeleteAction
    {
        return \Filament\Actions\DeleteAction::make()
            ->label(__('Eliminar'))
            ->visible(fn (\App\Models\AdvanceOrder $record) =>
                $record->status === AdvanceOrderStatus::CANCELLED
            )
            ->requiresConfirmation()
            ->modalHeading(__('Eliminar Orden de Producción Cancelada'))
            ->modalDescription(__('¿Está seguro de que desea eliminar esta orden de producción cancelada? Esta acción eliminará la orden y todos sus registros relacionados (productos, pivots de orders y order_lines) de forma permanente.'))
            ->modalSubmitActionLabel(__('Sí, eliminar'))
            ->action(function (\App\Models\AdvanceOrder $record) {
                $repository = app(\App\Repositories\AdvanceOrderRepository::class);

                if (!$repository->canDeleteAdvanceOrder($record)) {
                    \Filament\Notifications\Notification::make()
                        ->danger()
                        ->title(__('No se puede eliminar'))
                        ->body(__('Solo se pueden eliminar órdenes de producción canceladas.'))
                        ->send();

                    return;
                }

                try {
                    $repository->deleteAdvanceOrder($record);

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title(__('Orden de producción eliminada exitosamente'))
                        ->send();

                    // Redirect to index page
                    return redirect()->route('filament.admin.resources.advance-orders.index');
                } catch (\Exception $e) {
                    \Filament\Notifications\Notification::make()
                        ->danger()
                        ->title(__('Error al eliminar'))
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }
}
