<?php

namespace App\Filament\Resources;

use App\Enums\AdvanceOrderStatus;
use App\Filament\Resources\AdvanceOrderResource\Pages;
use App\Filament\Resources\AdvanceOrderResource\RelationManagers;
use App\Models\AdvanceOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
                                    ->before('initial_dispatch_date')
                                    ->validationMessages([
                                        'before' => 'La fecha y hora de elaboración debe ser anterior a la fecha inicial de despacho.',
                                    ])
                                    ->columnSpan(1),
                                Forms\Components\DatePicker::make('initial_dispatch_date')
                                    ->label(__('Fecha Inicial de Despacho'))
                                    ->required()
                                    ->native(false)
                                    ->after('preparation_datetime')
                                    ->beforeOrEqual('final_dispatch_date')
                                    ->validationMessages([
                                        'after' => 'La fecha inicial de despacho debe ser posterior a la fecha de elaboración.',
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
                Tables\Columns\TextColumn::make('initial_dispatch_date')
                    ->label(__('Fecha Inicial Despacho'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('final_dispatch_date')
                    ->label(__('Fecha Final Despacho'))
                    ->date()
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
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Fecha Creación'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options([
                        AdvanceOrderStatus::PENDING->value => AdvanceOrderStatus::PENDING->label(),
                        AdvanceOrderStatus::CANCELLED->value => AdvanceOrderStatus::CANCELLED->label(),
                        AdvanceOrderStatus::EXECUTED->value => AdvanceOrderStatus::EXECUTED->label(),
                    ]),
                Tables\Filters\TernaryFilter::make('use_products_in_orders')
                    ->label(__('Usar en Pedidos'))
                    ->placeholder(__('Todos'))
                    ->trueLabel(__('Sí'))
                    ->falseLabel(__('No'))
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('preparation_datetime', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ProductsRelationManager::class,
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
            ->modalDescription(__('¿Está seguro de que desea cancelar esta orden de producción? Esta acción cancelará la transacción de bodega y revertirá el inventario.'))
            ->modalSubmitActionLabel(__('Sí, cancelar'))
            ->visible(fn (\App\Models\AdvanceOrder $record) => $record->status === AdvanceOrderStatus::EXECUTED)
            ->action(function (\App\Models\AdvanceOrder $record) {
                $record->refresh();

                if ($record->status !== AdvanceOrderStatus::EXECUTED) {
                    \Filament\Notifications\Notification::make()
                        ->warning()
                        ->title(__('Estado inválido'))
                        ->body(__('Esta orden no puede ser cancelada en su estado actual.'))
                        ->send();
                    return;
                }

                $repository = new \App\Repositories\AdvanceOrderRepository();
                if (!$repository->canCancelAdvanceOrder($record)) {
                    \Filament\Notifications\Notification::make()
                        ->danger()
                        ->title(__('No se puede cancelar'))
                        ->body(__('Esta orden no puede ser cancelada porque existen otras órdenes posteriores con las mismas fechas de elaboración y despacho.'))
                        ->send();
                    return;
                }

                $record->update(['status' => AdvanceOrderStatus::CANCELLED]);
                event(new \App\Events\AdvanceOrderCancelled($record));

                \Filament\Notifications\Notification::make()
                    ->success()
                    ->title(__('Orden cancelada'))
                    ->body(__('La orden de producción ha sido cancelada exitosamente. El inventario ha sido revertido.'))
                    ->send();

                return redirect()->to(static::getUrl('edit', ['record' => $record]));
            });
    }
}
