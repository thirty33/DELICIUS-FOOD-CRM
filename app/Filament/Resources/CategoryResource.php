<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Resources\CategoryResource\RelationManagers;
use App\Models\Category;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TimePicker;
use Filament\Forms;
use App\Enums\Subcategory;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Almacén');
    }

    public static function getLabel(): ?string
    {
        return __('Categoría');
    }

    public static function getNavigationLabel(): string
    {
        return __('Categorías');
    }

    protected static ?string $navigationIcon = 'bx-category-alt';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->autofocus()
                    ->required()
                    ->minLength(2)
                    ->maxLength(200)
                    ->unique(static::getModel(), 'name', ignoreRecord: true)
                    ->label(__('Nombre'))
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(__('Descripción'))
                    ->rows(2)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('Activo'))
                    ->default(true)
                    ->inline(false),
                // Forms\Components\Select::make('subcategory')
                //     ->label(__('Subcategoría'))
                //     ->options(Subcategory::getSelectOptions())
                //     ->nullable()
                //     ->default(null),
                Forms\Components\Select::make('subcategories')
                    ->relationship(
                        name: 'subcategories',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn($query) => $query->distinct()
                    )
                    ->multiple()
                    ->label(__('Subcategorías'))
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable()
                    ->description(fn(Category $category) => $category->description),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('Activo'))
                    ->sortable(),
                // Tables\Columns\TextColumn::make('subcategory')-
                //     ->label(__('Subcategoría'))
                //     ->formatStateUsing(fn(string $state): string => Subcategory::from($state)->getLabel())
                //     ->searchable()
                //     ->sortable(),
                Tables\Columns\TextColumn::make('subcategories.name')
                    ->label(__('Subcategorías'))
                    ->badge(),
            ])
            ->filters([])
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
            ])
            ->emptyStateDescription(__('No hay categorías disponibles'));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CategoryLinesRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
