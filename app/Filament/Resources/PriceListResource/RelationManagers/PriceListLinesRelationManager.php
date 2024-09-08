<?php

namespace App\Filament\Resources\PriceListResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Pelmered\FilamentMoneyField\Forms\Components\MoneyInput;
use Pelmered\FilamentMoneyField\Tables\Columns\MoneyColumn;

class PriceListLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'priceListLines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Precios de la lista #:id#', ['id' => $ownerRecord->id]);
    }

    protected static function getRecordLabel(): ?string
    {
        return __('Línea de lista de precio');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('price_list_id')
                    ->default($this->ownerRecord->id),
                Forms\Components\Grid::make()
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->label(__('Producto'))
                            ->placeholder(__('Selecciona un producto'))
                            ->options(
                                Product::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->required()
                        // ->searchable()
                        // ->reactive()
                        // ->afterStateUpdated(function (Forms\Components\Select $component, Forms\Set $set) {
                        //     $product = Product::query()
                        //         ->where('id', $component->getState())
                        //         ->first();

                        //     $set('unit_price', $product?->price ?? 0);
                        // }),
                        ,
                        MoneyInput::make('unit_price')
                            ->label(__('Precio unitario'))
                            ->placeholder(__('Precio unitario del producto'))
                            ->currency('CLP')
                            ->minValue(0)
                            ->maxValue(1000000000)
                            ->decimals(2),
                    ])
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id'),
                Tables\Columns\TextColumn::make('id'),
                Tables\Columns\TextColumn::make('product.name')
                    ->label(__('Producto'))
                    ->searchable()
                    ->sortable(),
                MoneyColumn::make('unit_price')
                    ->currency('CLP'),
                // Tables\Columns\TextColumn::make('unit_price')
                //     ->label(__('Precio unitario'))
                //     ->sortable()
                //     ->money('$'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }
}
