<?php

namespace App\Filament\Resources\MenuResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
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
                        Forms\Components\Select::make('products')
                            ->relationship(
                                name: 'products',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn(Builder $query, callable $get) =>
                                $query->when(
                                    $get('category_id'),
                                    fn($query, $categoryId) => $query->where('category_id', $categoryId)
                                )
                            )
                            ->multiple()
                            ->label(__('Productos mostrados'))
                            ->columnSpanFull()
                            ->searchable()
                            ->hidden(fn($get) => $get('show_all_products'))
                            ->requiredIf('show_all_products', false)
                            ->validationMessages([
                                'required_if' => __('Debe agregar al menos un producto.')
                            ])
                            ->getOptionLabelFromRecordUsing(fn(Model $record) => $record->title_product),
                        Forms\Components\TextInput::make('display_order')
                            ->label(__('Orden de visualización'))
                            ->numeric()
                            ->required(),
                    ])
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('category.name'),
                Tables\Columns\ToggleColumn::make('show_all_products')
                    ->label(__('Mostrar todos productos'))
                    ->disabled(true),
                Tables\Columns\TextColumn::make('display_order')
                    ->label(__('Orden de visualización'))
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                // Tables\Actions\AttachAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Tables\Actions\DetachAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Tables\Actions\DetachBulkAction::make(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
