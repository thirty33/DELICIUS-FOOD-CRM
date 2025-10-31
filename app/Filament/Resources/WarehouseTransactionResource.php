<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarehouseTransactionResource\Pages;
use App\Models\WarehouseTransaction;
use App\Models\Warehouse;
use App\Models\Product;
use App\Enums\WarehouseTransactionStatus;
use App\Repositories\WarehouseTransactionRepository;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class WarehouseTransactionResource extends Resource
{
    protected static ?string $model = WarehouseTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): ?string
    {
        return __('Inventario');
    }

    public static function getLabel(): ?string
    {
        return __('Transacción');
    }

    public static function getNavigationLabel(): string
    {
        return __('Transacciones de Bodega');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Transacciones de Bodega');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Información de la Transacción'))
                    ->schema([
                        Forms\Components\Hidden::make('warehouse_id')
                            ->default(fn() => Warehouse::where('is_default', true)->first()?->id),
                        Forms\Components\Hidden::make('user_id')
                            ->default(fn() => auth()->id()),
                        Forms\Components\Hidden::make('transaction_code')
                            ->default(fn() => WarehouseTransaction::generateTransactionCode()),
                        Forms\Components\Placeholder::make('status_display')
                            ->label(__('Estado'))
                            ->content(fn ($record) => $record?->status ?
                                new \Illuminate\Support\HtmlString(
                                    '<span class="fi-badge fi-color-' . $record->status->color() . ' flex items-center justify-center gap-x-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset">
                                        ' . $record->status->label() . '
                                    </span>'
                                ) : '-'
                            )
                            ->visible(fn ($context) => $context === 'edit'),
                        Forms\Components\Placeholder::make('advance_order_display')
                            ->label(__('Orden de Producción Asociada'))
                            ->content(fn ($record) => $record?->advanceOrder ?
                                new \Illuminate\Support\HtmlString(
                                    '<a href="' . route('filament.admin.resources.advance-orders.edit', $record->advanceOrder) . '"
                                        class="text-primary-600 hover:text-primary-900 font-medium">
                                        Orden #' . $record->advanceOrder->id . ' - ' . $record->advanceOrder->preparation_datetime->format('d/m/Y H:i') . '
                                    </a>'
                                ) : '-'
                            )
                            ->visible(fn ($context, $record) => $context === 'edit' && $record?->advanceOrder !== null),
                        Forms\Components\Textarea::make('reason')
                            ->label(__('Motivo de la transacción'))
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make(__('Productos a Modificar'))
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->relationship('lines')
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label(__('Producto'))
                                    ->options(Product::query()->orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        if (!$state) return;

                                        $warehouse = Warehouse::where('is_default', true)->first();
                                        $warehouseProduct = \DB::table('warehouse_product')
                                            ->where('warehouse_id', $warehouse->id)
                                            ->where('product_id', $state)
                                            ->first();

                                        if ($warehouseProduct) {
                                            $set('stock_before', $warehouseProduct->stock);
                                            $set('unit_of_measure', $warehouseProduct->unit_of_measure);
                                        }
                                    })
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->disabled(fn ($context, $livewire) =>
                                        $context === 'edit' && $livewire->record && $livewire->record->status === WarehouseTransactionStatus::EXECUTED
                                    )
                                    ->columnSpan(3),
                                Forms\Components\TextInput::make('stock_before')
                                    ->label(__('Stock Actual'))
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('stock_after')
                                    ->label(__('Stock Nuevo'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        $stockBefore = $get('stock_before') ?? 0;
                                        $set('difference', $state - $stockBefore);
                                    })
                                    ->disabled(fn ($context, $livewire) =>
                                        $context === 'edit' && $livewire->record && $livewire->record->status === WarehouseTransactionStatus::EXECUTED
                                    )
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('difference')
                                    ->label(__('Diferencia'))
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('unit_of_measure')
                                    ->label(__('Unidad'))
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(1),
                            ])
                            ->columns(7)
                            ->reorderable(false)
                            ->addActionLabel(__('Agregar Producto'))
                            ->minItems(1)
                            ->deletable(fn ($context, $livewire) =>
                                $context === 'create' ||
                                ($livewire->record && $livewire->record->status !== WarehouseTransactionStatus::EXECUTED)
                            )
                            ->addable(fn ($context, $livewire) =>
                                $context === 'create' ||
                                ($livewire->record && $livewire->record->status !== WarehouseTransactionStatus::EXECUTED)
                            )
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transaction_code')
                    ->label(__('Código'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label(__('Bodega'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (WarehouseTransactionStatus $state): string => $state->color())
                    ->formatStateUsing(fn (WarehouseTransactionStatus $state): string => $state->label()),
                Tables\Columns\TextColumn::make('reason')
                    ->label(__('Motivo'))
                    ->limit(50)
                    ->searchable(),
                Tables\Columns\TextColumn::make('lines_count')
                    ->label(__('# Productos'))
                    ->counts('lines')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.nickname')
                    ->label(__('Creado por'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Fecha creación'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('executedBy.nickname')
                    ->label(__('Ejecutado por'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('executed_at')
                    ->label(__('Fecha ejecución'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options([
                        'pending' => __('Pendiente'),
                        'executed' => __('Ejecutada'),
                        'cancelled' => __('Cancelada'),
                    ]),
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->label(__('Bodega'))
                    ->relationship('warehouse', 'name'),
            ])
            ->actions([
                static::getTableExecuteAction(),
                static::getTableCancelAction(),
            ])
            ->bulkActions([
                // No bulk actions
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouseTransactions::route('/'),
            'create' => Pages\CreateWarehouseTransaction::route('/create'),
            'edit' => Pages\EditWarehouseTransaction::route('/{record}/edit'),
        ];
    }

    protected static function executeTransactionLogic(WarehouseTransaction $record): void
    {
        $repository = new WarehouseTransactionRepository();
        $success = $repository->executeTransaction($record, auth()->id());

        if ($success) {
            \Filament\Notifications\Notification::make()
                ->title(__('Transacción ejecutada'))
                ->success()
                ->send();
        } else {
            \Filament\Notifications\Notification::make()
                ->title(__('Error al ejecutar transacción'))
                ->danger()
                ->send();
        }
    }

    protected static function cancelTransactionLogic(WarehouseTransaction $record, string $reason): void
    {
        $repository = new WarehouseTransactionRepository();
        $success = $repository->cancelTransaction($record, auth()->id(), $reason);

        if ($success) {
            \Filament\Notifications\Notification::make()
                ->title(__('Transacción cancelada'))
                ->success()
                ->send();
        } else {
            \Filament\Notifications\Notification::make()
                ->title(__('Error al cancelar transacción'))
                ->danger()
                ->send();
        }
    }

    public static function getTableExecuteAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('execute_transaction')
            ->label(__('Ejecutar'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('¿Ejecutar transacción?'))
            ->modalDescription(__('Esta acción actualizará el stock de los productos en la bodega.'))
            ->modalSubmitActionLabel(__('Sí, ejecutar'))
            ->visible(fn (WarehouseTransaction $record) => $record->canExecute())
            ->action(fn (WarehouseTransaction $record) => static::executeTransactionLogic($record));
    }

    public static function getTableCancelAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancel_transaction')
            ->label(__('Cancelar'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->form([
                Forms\Components\Textarea::make('cancellation_reason')
                    ->label(__('Motivo de cancelación'))
                    ->required()
                    ->rows(3),
            ])
            ->modalHeading(__('¿Cancelar transacción?'))
            ->modalDescription(__('Esta acción restaurará el stock anterior de los productos.'))
            ->modalSubmitActionLabel(__('Sí, cancelar'))
            ->visible(fn (WarehouseTransaction $record) => $record->canCancel())
            ->action(fn (WarehouseTransaction $record, array $data) =>
                static::cancelTransactionLogic($record, $data['cancellation_reason'])
            );
    }

    public static function getHeaderExecuteAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('execute_transaction')
            ->label(__('Ejecutar'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('¿Ejecutar transacción?'))
            ->modalDescription(__('Esta acción actualizará el stock de los productos en la bodega.'))
            ->modalSubmitActionLabel(__('Sí, ejecutar'))
            ->visible(fn (WarehouseTransaction $record) => $record->canExecute())
            ->action(function (WarehouseTransaction $record) {
                static::executeTransactionLogic($record);
                return redirect()->to(WarehouseTransactionResource::getUrl('edit', ['record' => $record]));
            });
    }

    public static function getHeaderCancelAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('cancel_transaction')
            ->label(__('Cancelar'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->form([
                Forms\Components\Textarea::make('cancellation_reason')
                    ->label(__('Motivo de cancelación'))
                    ->required()
                    ->rows(3),
            ])
            ->modalHeading(__('¿Cancelar transacción?'))
            ->modalDescription(__('Esta acción restaurará el stock anterior de los productos.'))
            ->modalSubmitActionLabel(__('Sí, cancelar'))
            ->visible(fn (WarehouseTransaction $record) => $record->canCancel())
            ->action(function (WarehouseTransaction $record, array $data) {
                static::cancelTransactionLogic($record, $data['cancellation_reason']);
                return redirect()->to(WarehouseTransactionResource::getUrl('edit', ['record' => $record]));
            });
    }
}
