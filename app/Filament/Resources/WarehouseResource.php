<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarehouseResource\Pages;
use App\Filament\Resources\WarehouseResource\RelationManagers;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Inventario');
    }

    public static function getLabel(): ?string
    {
        return __('Bodega');
    }

    public static function getNavigationLabel(): string
    {
        return __('Bodegas');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Bodegas');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('Nombre'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('code')
                            ->label(__('Código'))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->columnSpan(1),
                        Forms\Components\Textarea::make('address')
                            ->label(__('Dirección'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('active')
                            ->label(__('Activa'))
                            ->default(true)
                            ->required()
                            ->columnSpan(1),
                        Forms\Components\Toggle::make('is_default')
                            ->label(__('Bodega por defecto'))
                            ->disabled(fn ($context) => $context === 'edit')
                            ->helperText(fn ($context) => $context === 'edit'
                                ? __('No se puede modificar en edición')
                                : __('Solo puede haber una bodega por defecto')
                            )
                            ->columnSpan(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('code')
                    ->label(__('Código'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('address')
                    ->label(__('Dirección'))
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('active')
                    ->label(__('Activa'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_default')
                    ->label(__('Por defecto'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->label(__('Productos'))
                    ->counts('products')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Fecha creación'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Fecha actualización'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label(__('Activa'))
                    ->placeholder(__('Todas'))
                    ->trueLabel(__('Activas'))
                    ->falseLabel(__('Inactivas')),
                Tables\Filters\TernaryFilter::make('is_default')
                    ->label(__('Por defecto'))
                    ->placeholder(__('Todas'))
                    ->trueLabel(__('Sí'))
                    ->falseLabel(__('No')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                // No bulk actions - cannot delete warehouses
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouses::route('/'),
            'edit' => Pages\EditWarehouse::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
