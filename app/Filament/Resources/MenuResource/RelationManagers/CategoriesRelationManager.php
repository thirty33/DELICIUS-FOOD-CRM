<?php

namespace App\Filament\Resources\MenuResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Hidden;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Closure;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categoryMenus';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Categorías del menú: :title', ['title' => $ownerRecord->title]);
    }

    protected static function getRecordLabel(): ?string
    {
        return __('Categoría');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Hidden::make('menu_id')
                    ->default(fn(RelationManager $livewire) => $livewire->getOwnerRecord()->id),
                Forms\Components\Grid::make()
                    ->columns(3)
                    ->schema([
                        Select::make('category_id')
                            ->label(__('Categoría'))
                            ->options(Category::query()->pluck('name', 'id'))
                            ->unique(
                                table: 'category_menu',
                                column: 'category_id',
                                modifyRuleUsing: function (Unique $rule) {
                                    return $rule->where('menu_id', $this->getOwnerRecord()->id);
                                },
                                ignoreRecord: true
                            )
                            ->required()
                            ->live() // Esto hace que el campo sea reactivo
                            ->columns(1),
                        Toggle::make('show_all_products')
                            ->label(__('Mostrar todos los productos'))
                            ->default(true)
                            ->inline(false)
                            ->live() // Hacemos que el toggle sea reactivo
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $set('products', []); // Limpiamos la selección cuando show_all_products es true
                                }
                            })
                            ->columns(1),
                        Forms\Components\Repeater::make('product_configs')
                            ->label(__('Productos y orden de visualización'))
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label(__('Producto'))
                                    ->options(function (Get $get) {
                                        $categoryId = $get('../../category_id');
                                        if (!$categoryId) {
                                            return [];
                                        }

                                        $category = Category::find($categoryId);
                                        $query = Product::where('active', true);

                                        // If the category is NOT dynamic, filter by category_id
                                        if (!$category?->is_dynamic) {
                                            $query->where('category_id', $categoryId);
                                        }

                                        return $query->get()
                                            ->mapWithKeys(fn($product) => [
                                                $product->id => "{$product->code} - {$product->name}"
                                            ]);
                                    })
                                    ->searchable()
                                    ->required()
                                    ->disableOptionWhen(function ($value, $state, Get $get) {
                                        $configs = $get('../../product_configs') ?? [];
                                        foreach ($configs as $config) {
                                            if (isset($config['product_id']) &&
                                                $config['product_id'] == $value &&
                                                $config['product_id'] != $state) {
                                                return true;
                                            }
                                        }
                                        return false;
                                    }),
                                Forms\Components\TextInput::make('display_order')
                                    ->label(__('Orden'))
                                    ->numeric()
                                    ->default(9999)
                                    ->required()
                                    ->minValue(0),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->defaultItems(0)
                            ->addActionLabel(__('Agregar Producto'))
                            ->reorderable(true)
                            ->reorderableWithDragAndDrop(true)
                            ->orderColumn('display_order')
                            ->collapsible()
                            ->itemLabel(fn(array $state): ?string =>
                                isset($state['product_id'])
                                    ? Product::find($state['product_id'])?->name
                                    : null
                            )
                            ->hidden(fn(Get $get) => $get('show_all_products'))
                            ->helperText(__('Agregue los productos que desea mostrar y su orden de visualización'))
                            ->afterStateHydrated(function ($state, $record, Set $set) {
                                if ($record && $record->exists) {
                                    $productConfigs = $record->products()
                                        ->orderBy('category_menu_product.display_order')
                                        ->get()
                                        ->map(fn($product) => [
                                            'product_id' => $product->id,
                                            'display_order' => $product->pivot->display_order,
                                        ])
                                        ->toArray();
                                    $set('product_configs', $productConfigs);
                                }
                            }),

                        Forms\Components\Section::make(__('Productos con orden personalizado'))
                            ->description(__('Cuando "Mostrar todos los productos" está activo, puede especificar un orden personalizado para productos específicos. Los demás productos usarán orden 9999.'))
                            ->schema([
                                Forms\Components\Repeater::make('custom_product_orders')
                                    ->label(__('Productos con orden personalizado'))
                                    ->schema([
                                        Forms\Components\Select::make('product_id')
                                            ->label(__('Producto'))
                                            ->options(function (Get $get) {
                                                $categoryId = $get('../../category_id');
                                                if (!$categoryId) {
                                                    return [];
                                                }
                                                return Product::where('category_id', $categoryId)
                                                    ->where('active', true)
                                                    ->get()
                                                    ->mapWithKeys(fn($product) => [
                                                        $product->id => "{$product->code} - {$product->name}"
                                                    ]);
                                            })
                                            ->searchable()
                                            ->required()
                                            ->disableOptionWhen(function ($value, $state, Get $get) {
                                                $configs = $get('../../custom_product_orders') ?? [];
                                                foreach ($configs as $config) {
                                                    if (isset($config['product_id']) &&
                                                        $config['product_id'] == $value &&
                                                        $config['product_id'] != $state) {
                                                        return true;
                                                    }
                                                }
                                                return false;
                                            }),
                                        Forms\Components\TextInput::make('display_order')
                                            ->label(__('Orden'))
                                            ->numeric()
                                            ->default(1)
                                            ->required()
                                            ->minValue(0),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull()
                                    ->defaultItems(0)
                                    ->addActionLabel(__('Agregar Producto con orden'))
                                    ->reorderable(true)
                                    ->collapsible()
                                    ->itemLabel(fn(array $state): ?string =>
                                        isset($state['product_id'])
                                            ? Product::find($state['product_id'])?->name . ' (orden: ' . ($state['display_order'] ?? '?') . ')'
                                            : null
                                    )
                                    ->afterStateHydrated(function ($state, $record, Set $set) {
                                        if ($record && $record->exists && $record->show_all_products) {
                                            $customOrders = $record->products()
                                                ->wherePivot('display_order', '!=', 9999)
                                                ->orderBy('category_menu_product.display_order')
                                                ->get()
                                                ->map(fn($product) => [
                                                    'product_id' => $product->id,
                                                    'display_order' => $product->pivot->display_order,
                                                ])
                                                ->toArray();
                                            $set('custom_product_orders', $customOrders);
                                        }
                                    }),
                            ])
                            ->visible(fn(Get $get) => $get('show_all_products'))
                            ->collapsible()
                            ->collapsed(),
                        Forms\Components\TextInput::make('display_order')
                            ->label(__('Orden de visualización'))
                            ->numeric()
                            ->required()
                            ->default(100),
                        Toggle::make('mandatory_category')
                            ->label(__('Categoría obligatoria'))
                            ->default(false)
                            ->columns(1),
                        Toggle::make('is_active')
                            ->label(__('Activo'))
                            ->default(true)
                            ->columns(1),
                    ])
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('Categoría'))
                    ->formatStateUsing(function ($record) {
                        $categoryName = $record->category->name;
                        $subcategories = $record->category->subcategories;

                        if ($subcategories->isEmpty()) {
                            return $categoryName;
                        }

                        $subcategoryNames = $subcategories->pluck('name')->join(', ');
                        return $categoryName . ' (' . $subcategoryNames . ')';
                    })
                    ->wrap(),
                Tables\Columns\ToggleColumn::make('show_all_products')
                    ->label(__('Mostrar todos productos'))
                    ->disabled(true),
                Tables\Columns\ToggleColumn::make('mandatory_category')
                    ->label(__('Categoría obligatoria')),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('Activo')),
                Tables\Columns\TextColumn::make('display_order')
                    ->label(__('Orden de visualización'))
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->using(function (array $data, string $model): Model {
                        $productConfigs = $data['product_configs'] ?? [];
                        $customProductOrders = $data['custom_product_orders'] ?? [];
                        unset($data['product_configs'], $data['custom_product_orders'], $data['products']);

                        $record = $model::create($data);

                        $this->syncProductsToRecord($record, $data, $productConfigs, $customProductOrders);

                        return $record;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (CategoryMenu $record, array $data): CategoryMenu {
                        $productConfigs = $data['product_configs'] ?? [];
                        $customProductOrders = $data['custom_product_orders'] ?? [];
                        unset($data['product_configs'], $data['custom_product_orders'], $data['products']);

                        $record->update($data);

                        $this->syncProductsToRecord($record, $data, $productConfigs, $customProductOrders);

                        return $record;
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Tables\Actions\DetachBulkAction::make(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('display_order', 'asc');
    }

    /**
     * Sync products to a CategoryMenu record based on show_all_products setting.
     *
     * @param CategoryMenu $record
     * @param array $data
     * @param array $productConfigs
     * @param array $customProductOrders
     * @return void
     */
    protected function syncProductsToRecord(
        CategoryMenu $record,
        array $data,
        array $productConfigs,
        array $customProductOrders
    ): void {
        $showAllProducts = $data['show_all_products'] ?? false;

        if ($showAllProducts) {
            // When show_all_products is true, sync ALL products from the category
            // but apply custom display_order to specified products
            $allProductIds = Product::where('category_id', $record->category_id)
                ->where('active', true)
                ->pluck('id')
                ->toArray();

            $customOrders = collect($customProductOrders)
                ->filter(fn($config) => !empty($config['product_id']))
                ->mapWithKeys(fn($config) => [
                    $config['product_id'] => ['display_order' => (int) ($config['display_order'] ?? 9999)]
                ])
                ->toArray();

            $syncData = [];
            foreach ($allProductIds as $productId) {
                if (isset($customOrders[$productId])) {
                    $syncData[$productId] = $customOrders[$productId];
                } else {
                    $syncData[$productId] = ['display_order' => 9999];
                }
            }

            $record->products()->sync($syncData);
        } else {
            // When show_all_products is false, only sync the specified products
            $syncData = collect($productConfigs)
                ->filter(fn($config) => !empty($config['product_id']))
                ->mapWithKeys(fn($config) => [
                    $config['product_id'] => ['display_order' => (int) ($config['display_order'] ?? 9999)]
                ])
                ->toArray();

            $record->products()->sync($syncData);
        }
    }
}
