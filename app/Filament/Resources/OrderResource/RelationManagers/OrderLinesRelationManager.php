<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OrderLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'orderLines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Líneas del pedido #:id#', ['id' => $ownerRecord->id]);
    }

    protected static function getRecordLabel(): ?string
    {
        return __('Línea de pedido');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('order_id')
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
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function (Forms\Components\Select $component, Forms\Set $set) {
                                $product = Product::query()
                                    ->where('id', $component->getState())
                                    ->first();

                                $set('unit_price', $product?->price ?? 0);
                            }),
                        Forms\Components\TextInput::make('quantity')
                            ->numeric()
                            ->label(__('Cantidad'))
                            ->required()
                            ->placeholder(__('Cantidad del producto'))
                            ->default(1),
                        Forms\Components\TextInput::make('unit_price')
                            ->label(__('Precio unitario'))
                            ->required()
                            ->placeholder(__('Precio unitario del producto'))
                            ->default(0)
                            ->suffix('€'),
                    ])
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id'),
                Tables\Columns\TextColumn::make('product.name')
                    ->label(__('Producto'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('Cantidad'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit_price')
                    ->label(__('Precio unitario'))
                    ->sortable()
                    ->money('eur'),
                Tables\Columns\TextColumn::make('total_price')
                    ->label(__('Precio total'))
                    ->sortable()
                    ->money('eur')
                    ->state(function (Model $record): float {
                        return $record->quantity * $record->unit_price;
                    })
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
