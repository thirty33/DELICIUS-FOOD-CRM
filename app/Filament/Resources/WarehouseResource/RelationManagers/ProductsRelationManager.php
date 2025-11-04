<?php

namespace App\Filament\Resources\WarehouseResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'warehouseProducts';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Productos en Bodega');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('warehouse_id')
                    ->default(fn() => $this->getOwnerRecord()->id),
                Forms\Components\Select::make('product_id')
                    ->label(__('Producto'))
                    ->options(Product::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->unique(
                        table: 'warehouse_product',
                        column: 'product_id',
                        modifyRuleUsing: function ($rule) {
                            return $rule->where('warehouse_id', $this->getOwnerRecord()->id);
                        },
                        ignoreRecord: true
                    )
                    ->validationMessages([
                        'unique' => __('Este producto ya existe en esta bodega.'),
                    ])
                    ->getOptionLabelFromRecordUsing(fn(Product $record) => "{$record->code} - {$record->name}")
                    ->columnSpanFull(),
                Forms\Components\Grid::make()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('stock')
                            ->label(__('Stock'))
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->disabled()
                            ->dehydrated()
                            ->helperText(__('El stock inicial es 0. Usa transacciones para modificar el stock.'))
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('unit_of_measure')
                            ->label(__('Unidad de Medida'))
                            ->default('UND')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                Tables\Columns\TextColumn::make('product.code')
                    ->label(__('Código'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.category.name')
                    ->label(__('Categoría'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('stock')
                    ->label(__('Stock'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit_of_measure')
                    ->label(__('Unidad'))
                    ->searchable(),
                Tables\Columns\IconColumn::make('product.active')
                    ->label(__('Activo'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('product.active')
                    ->label(__('Activo'))
                    ->placeholder(__('Todos'))
                    ->trueLabel(__('Activos'))
                    ->falseLabel(__('Inactivos')),
                Tables\Filters\SelectFilter::make('product.category_id')
                    ->label(__('Categoría'))
                    ->relationship('product.category', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('has_stock')
                    ->label(__('Stock'))
                    ->placeholder(__('Todos'))
                    ->trueLabel(__('Con stock (> 0)'))
                    ->falseLabel(__('Sin stock (= 0)'))
                    ->queries(
                        true: fn ($query) => $query->where('stock', '>', 0),
                        false: fn ($query) => $query->where('stock', '=', 0),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Vincular producto'))
                    ->modalHeading(__('Vincular producto')),
            ])
            ->actions([
                // No edit/delete actions - stock must be managed through transactions
            ])
            ->bulkActions([
                // No bulk actions - stock must be managed through transactions
            ])
            ->defaultSort('product.name');
    }
}
