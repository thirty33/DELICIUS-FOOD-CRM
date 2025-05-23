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
use App\Models\PriceListLine;
use Filament\Forms\Components\Toggle;
use Illuminate\Validation\Rules\Unique;

class PriceListLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'priceListLines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Precios de la lista :name', ['name' => $ownerRecord->name]);
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
                            ->relationship(
                                name: 'product',
                                titleAttribute: 'name'
                            )
                            ->required()
                            ->unique(
                                table: PriceListLine::class,
                                column: 'product_id',
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule) {
                                    return $rule->where('price_list_id', $this->ownerRecord->id);
                                }
                            )
                            ->validationMessages([
                                'unique' => __('Este producto ya está en la lista de precios.'),
                            ])
                            ->searchable()
                            ->getOptionLabelFromRecordUsing(fn(Model $record) => $record->title_product),
                        MoneyInput::make('unit_price')
                            ->label(__('Precio unitario'))
                            ->placeholder(__('Precio unitario del producto'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->required()
                            ->minValue(0)
                            ->decimals(2)
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
                Tables\Columns\TextColumn::make('product.code')
                    ->label(__('Código'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.name')
                    ->label(__('Producto'))
                    ->searchable()
                    ->sortable(),
                MoneyColumn::make('unit_price')
                    ->currency('USD')
                    ->locale('en_US')
                    ->decimals(2),
                Tables\Columns\ToggleColumn::make('active')
                    ->label(__('Activo'))
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
