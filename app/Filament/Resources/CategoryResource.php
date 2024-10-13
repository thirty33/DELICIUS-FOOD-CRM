<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TimePicker;

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
                TextInput::make('preparation_days')
                    ->label(__('Días de Preparación'))
                    ->numeric()
                    ->default(0),
                TextInput::make('preparation_hours')
                    ->label(__('Horas de Preparación'))
                    ->numeric()
                    ->default(0)
                    ->maxValue(24),
                TextInput::make('preparation_minutes')
                    ->label(__('Minutos de Preparación'))
                    ->numeric()
                    ->default(0)
                    ->maxValue(60),
                Toggle::make('is_active')
                    ->label(__('Activo'))
                    ->default(true)
                    ->inline(false),
                TimePicker::make('order_start_time')
                    ->label(__('Hora de Inicio de Pedidos'))
                    ->seconds(false),
                TimePicker::make('order_end_time')
                    ->label(__('Hora Máxima de Pedidos'))
                    ->seconds(false),
                Toggle::make('is_active_monday')
                    ->label(__('Activo Lunes')),
                Toggle::make('is_active_tuesday')
                    ->label(__('Activo Martes')),
                Toggle::make('is_active_wednesday')
                    ->label(__('Activo Miércoles')),
                Toggle::make('is_active_thursday')
                    ->label(__('Activo Jueves')),
                Toggle::make('is_active_friday')
                    ->label(__('Activo Viernes')),
                Toggle::make('is_active_saturday')
                    ->label(__('Activo Sábado')),
                Toggle::make('is_active_sunday')
                    ->label(__('Activo Domingo')),
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
                Tables\Columns\TextColumn::make('order_start_time')
                    ->label(__('Hora de Inicio de Pedidos'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_end_time')
                    ->label(__('Hora Máxima de Pedidos'))
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('Activo'))
                    ->sortable(),
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
            //
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
