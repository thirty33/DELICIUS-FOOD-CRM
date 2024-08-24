<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Almacén');
    }

    public static function getLabel(): ?string
    {
        return __('Producto');
    }

    public static function getNavigationLabel(): string
    {
        return __('Productos');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('image')
                    ->label(__('Imagen'))
                    ->image()
                    ->maxSize(4096)
                    ->placeholder(__('Imagen del producto'))
                    ->columnSpanFull(),
                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->autofocus()
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->unique(static::getModel(), 'name', ignoreRecord: true)
                            ->label(__('Nombre'))
                            ->columns(1),
                        Forms\Components\TextInput::make('price')
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Precio'))
                            ->columns(1),
                        Forms\Components\Select::make('category_id')
                            ->relationship('category', 'name')
                            ->required()
                            ->label(__('Categoría'))
                            ->searchable()
                            ->columns(1)
                    ])->columns(3),
                Forms\Components\Textarea::make('description')
                    ->required()
                    ->minLength(2)
                    ->maxLength(200)
                    ->label(__('Descripción'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('Imagen')),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('Precio'))
                    ->sortable()
                    ->money('eur'),
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('Categoría'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Actualizado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->relationship('category', 'name')
                    ->label(__('Categoría')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            ->emptyStateDescription(__('No hay productos disponibles'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                ImageEntry::make('image')
                    ->hiddenLabel()
                    ->columnSpanFull(),
                Section::make()->schema([
                    TextEntry::make('name')->label(__('Nombre')),
                    TextEntry::make('price')->label(__('Precio'))->money('eur'),
                    TextEntry::make('category.name')->label(__('Categoría')),
                ])->columns(3),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
