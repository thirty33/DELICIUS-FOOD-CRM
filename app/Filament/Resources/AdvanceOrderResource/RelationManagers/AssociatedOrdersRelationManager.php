<?php

namespace App\Filament\Resources\AdvanceOrderResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;

class AssociatedOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'associatedOrders';

    protected static ?string $title = 'Pedidos Asociados';

    protected static ?string $modelLabel = 'Pedido';

    protected static ?string $pluralModelLabel = 'Pedidos';

    public function form(Form $form): Form
    {
        // This relation is read-only (managed by events)
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('# Pedido')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->copyable()
                    ->copyMessage('Número de pedido copiado')
                    ->url(fn ($record) => route('filament.admin.resources.orders.edit', ['record' => $record->order_id]))
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('order_dispatch_date')
                    ->label('Fecha Despacho')
                    ->date('d/m/Y')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('order_user_nickname')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order_status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'PROCESSED' => 'success',
                        'PARTIALLY_SCHEDULED' => 'info',
                        'CANCELLED' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => 'Pendiente',
                        'PROCESSED' => 'Procesado',
                        'PARTIALLY_SCHEDULED' => 'Parcialmente Agendado',
                        'CANCELLED' => 'Cancelado',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('order_total')
                    ->label('Total')
                    ->money('CLP')
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Asociado el')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('order_status')
                    ->label('Estado')
                    ->options([
                        'PENDING' => 'Pendiente',
                        'PROCESSED' => 'Procesado',
                        'PARTIALLY_SCHEDULED' => 'Parcialmente Agendado',
                        'CANCELLED' => 'Cancelado',
                    ]),

                Tables\Filters\Filter::make('order_dispatch_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('Desde'),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('Hasta'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($query, $date) => $query->where('order_dispatch_date', '>=', $date))
                            ->when($data['until'], fn ($query, $date) => $query->where('order_dispatch_date', '<=', $date));
                    }),
            ])
            ->defaultSort('order_dispatch_date', 'asc')
            ->headerActions([
                // No create/edit actions - this is read-only, managed by events
            ])
            ->actions([
                // View order details
                Tables\Actions\Action::make('view_order')
                    ->label('Ver Pedido')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => route('filament.admin.resources.orders.edit', ['record' => $record->order_id]))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                // No bulk actions - this is read-only
            ])
            ->emptyStateHeading('No hay pedidos asociados')
            ->emptyStateDescription('Los pedidos se asociarán automáticamente cuando agregues productos a esta orden de producción.')
            ->emptyStateIcon('heroicon-o-shopping-bag')
            ->poll('10s'); // Auto-refresh every 10 seconds
    }
}
