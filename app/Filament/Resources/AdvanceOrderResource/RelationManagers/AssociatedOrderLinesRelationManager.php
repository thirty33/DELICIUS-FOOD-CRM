<?php

namespace App\Filament\Resources\AdvanceOrderResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Builder;

class AssociatedOrderLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'associatedOrderLines';

    protected static ?string $title = 'Líneas de Pedido Asociadas';

    protected static ?string $modelLabel = 'Línea de Pedido';

    protected static ?string $pluralModelLabel = 'Líneas de Pedido';

    public function form(Form $form): Form
    {
        // This relation is read-only (managed by events)
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_name')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('# Pedido')
                    ->searchable()
                    ->sortable()
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

                Tables\Columns\TextColumn::make('product_name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->wrap(),

                Tables\Columns\TextColumn::make('product_code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('quantity_covered')
                    ->label('Cantidad Cubierta')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('success')
                    ->weight(FontWeight::Bold),

                Tables\Columns\TextColumn::make('order_line_unit_price')
                    ->label('Precio Unit.')
                    ->money('CLP')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order_line_total_price')
                    ->label('Total Línea')
                    ->money('CLP')
                    ->sortable()
                    ->alignEnd()
                    ->weight(FontWeight::Bold),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Asociado el')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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

                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Producto')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('order_dispatch_date', 'asc')
            ->groups([
                Tables\Grouping\Group::make('order_number')
                    ->label('Pedido')
                    ->collapsible(),

                Tables\Grouping\Group::make('product_name')
                    ->label('Producto')
                    ->collapsible(),

                Tables\Grouping\Group::make('order_dispatch_date')
                    ->label('Fecha Despacho')
                    ->date()
                    ->collapsible(),
            ])
            ->headerActions([
                // No create/edit actions - this is read-only, managed by events
            ])
            ->actions([
                Tables\Actions\Action::make('view_order')
                    ->label('Ver Pedido')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => route('filament.admin.resources.orders.edit', ['record' => $record->order_id]))
                    ->openUrlInNewTab()
                    ->tooltip('Ver detalles del pedido completo'),
            ])
            ->bulkActions([
                // No bulk actions - this is read-only
            ])
            ->emptyStateHeading('No hay líneas de pedido asociadas')
            ->emptyStateDescription('Las líneas de pedido se asociarán automáticamente cuando agregues productos a esta orden de producción.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->poll('10s') // Auto-refresh every 10 seconds
            ->recordAction(null); // Disable row click action
    }
}
