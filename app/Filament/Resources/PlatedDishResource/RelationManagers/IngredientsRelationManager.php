<?php

namespace App\Filament\Resources\PlatedDishResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class IngredientsRelationManager extends RelationManager
{
    protected static string $relationship = 'ingredients';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Ingredientes');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\TextInput::make('ingredient_name')
                            ->label(__('Nombre del Ingrediente'))
                            ->placeholder(__('Ej: Tomate cherry, Aceite de oliva, Sal marina'))
                            ->helperText(__('Nombre libre del ingrediente (no necesita ser un producto)'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\Select::make('measure_unit')
                            ->label(__('Unidad de Medida'))
                            ->options([
                                'GR' => 'Gramos (GR)',
                                'KG' => 'Kilogramos (KG)',
                                'ML' => 'Mililitros (ML)',
                                'L' => 'Litros (L)',
                                'UND' => 'Unidades (UND)',
                            ])
                            ->required()
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('quantity')
                            ->label(__('Cantidad'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.001)
                            ->required()
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('max_quantity_horeca')
                            ->label(__('Cantidad Máxima HORECA'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.001)
                            ->nullable()
                            ->helperText(__('Cantidad máxima para clientes HORECA (opcional)'))
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('shelf_life')
                            ->label(__('Vida Útil (días)'))
                            ->numeric()
                            ->minValue(1)
                            ->nullable()
                            ->helperText(__('Vida útil del ingrediente en días (opcional)'))
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('order_index')
                            ->label(__('Orden'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText(__('Orden de visualización del ingrediente'))
                            ->columnSpan(1),

                        Forms\Components\Toggle::make('is_optional')
                            ->label(__('Ingrediente Opcional'))
                            ->default(false)
                            ->columnSpan(1),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ingredient_name')
            ->defaultSort('order_index', 'asc')
            ->reorderable('order_index')
            ->columns([
                Tables\Columns\TextColumn::make('order_index')
                    ->label(__('Orden'))
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('gray')
                    ->width(80),

                Tables\Columns\TextColumn::make('ingredient_name')
                    ->label(__('Ingrediente'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('Cantidad'))
                    ->formatStateUsing(fn ($state, $record) => number_format($state, 2) . ' ' . $record->measure_unit)
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('max_quantity_horeca')
                    ->label(__('Cant. Máx. HORECA'))
                    ->formatStateUsing(fn ($state, $record) => $state ? number_format($state, 2) . ' ' . $record->measure_unit : '-')
                    ->toggleable()
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('shelf_life')
                    ->label(__('Vida Útil'))
                    ->formatStateUsing(fn ($state) => $state ? $state . ' días' : '-')
                    ->toggleable()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state <= 3 => 'danger',
                        $state <= 7 => 'warning',
                        $state <= 30 => 'success',
                        default => 'info',
                    }),

                Tables\Columns\IconColumn::make('is_optional')
                    ->label(__('Opcional'))
                    ->boolean()
                    ->toggleable()
                    ->sortable()
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_optional')
                    ->label(__('Ingredientes Opcionales'))
                    ->placeholder(__('Todos'))
                    ->trueLabel(__('Solo opcionales'))
                    ->falseLabel(__('Solo requeridos')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Agregar Ingrediente')),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(__('Editar')),
                Tables\Actions\DeleteAction::make()
                    ->label(__('Eliminar')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label(__('Eliminar seleccionados')),
                ]),
            ])
            ->emptyStateHeading(__('Sin ingredientes'))
            ->emptyStateDescription(__('Comienza agregando ingredientes a este plato emplatado.'))
            ->emptyStateIcon('heroicon-o-cube');
    }
}
