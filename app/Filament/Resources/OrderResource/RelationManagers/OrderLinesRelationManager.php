<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Models\Product;
use App\Models\PriceListLine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Pelmered\FilamentMoneyField\Forms\Components\MoneyInput;
use Pelmered\FilamentMoneyField\Tables\Columns\MoneyColumn;
use Livewire\Component as Livewire;

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
                            ->options(function () {
                                $order = $this->ownerRecord;
                    
                                $user = $order->user;
                    
                                $company = $user->company;
                
                                if (!$company || !$company->priceLists) {
                                    return [];
                                }
                    
                                $priceListId = $company->price_list_id;
                    
                                $products = Product::whereHas('priceListLines', function ($query) use ($priceListId) {
                                    $query->where('price_list_id', $priceListId);
                                })
                                ->whereHas('category', function ($query) {
                                    $query->where('is_active', true);
                                })
                                ->orderBy('name')
                                ->pluck('name', 'id');
                        
                                return $products;
                            })
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function (Forms\Components\Select $component, Forms\Set $set) {

                                $productId = $component->getState();
                                $product = Product::find($productId);

                                if (!$product) {
                                    $set('unit_price', 0);
                                    return;
                                }

                                $order = $this->ownerRecord;
                                $user = $order->user;
                                $company = $user->company;

                                $priceListLine = null;
                                if ($company && $company->priceLists) {
                                    $priceListLine = PriceListLine::where('price_list_id', $company->price_list_id)
                                        ->where('product_id', $productId)
                                        ->first();
                                }

                                if ($priceListLine) {
                                    $set('unit_price', $priceListLine->unit_price / 100);
                                } else {
                                    $productPrice = $product->price ?? 0;
                                    $set('unit_price', $productPrice > 0 ? $productPrice / 100 : 0);
                                }
                            }),
                        Forms\Components\TextInput::make('quantity')
                            ->numeric()
                            ->label(__('Cantidad'))
                            ->required()
                            ->placeholder(__('Cantidad del producto'))
                            ->default(1),
                        MoneyInput::make('unit_price')
                            ->label(__('Precio unitario'))
                            ->placeholder(__('Precio unitario del producto'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
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
                Tables\Columns\TextColumn::make('product.name')
                    ->label(__('Producto'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('Cantidad'))
                    ->sortable(),
                MoneyColumn::make('unit_price')
                    ->label(__('Precio unitario'))
                    ->currency('USD')
                    ->locale('en_US'),
                MoneyColumn::make('total_price')
                    ->label(__('Precio total'))
                    ->currency('USD')
                    ->locale('en_US')
                    ->state(function (Model $record): float {
                        return $record->quantity * $record->unit_price;
                    }),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->after(function ($record, Livewire $livewire) {
                        $livewire->dispatch('refreshForm');
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->after(function ($record, Livewire $livewire) {
                        $livewire->dispatch('refreshForm');
                    }),
                Tables\Actions\DeleteAction::make()
                    ->after(function ($record, Livewire $livewire) {
                        $livewire->dispatch('refreshForm');
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->after(function ($record, Livewire $livewire) {
                            $livewire->dispatch('refreshForm');
                        }),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->after(function ($record, Livewire $livewire) {
                        $livewire->dispatch('refreshForm');
                    }),
            ]);
    }
}
