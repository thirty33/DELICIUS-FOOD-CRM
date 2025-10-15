<?php

namespace App\Filament\Resources\OrderRuleResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\Subcategory;

class SubcategoryLimitsRelationManager extends RelationManager
{
    protected static string $relationship = 'subcategoryLimits';

    protected static ?string $title = 'Límites de Productos por Subcategoría';

    protected static ?string $recordTitleAttribute = 'subcategory.name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Configuración de Límite')
                    ->description('Define el límite máximo de productos permitidos para una subcategoría específica')
                    ->schema([
                        Forms\Components\Select::make('subcategory_id')
                            ->label('Subcategoría')
                            ->options(Subcategory::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('La subcategoría a la que se aplicará el límite')
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('max_products')
                            ->label('Límite Máximo de Productos')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(1)
                            ->helperText('Número máximo de productos permitidos de esta subcategoría')
                            ->suffix('productos')
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Explicación')
                    ->schema([
                        Forms\Components\Placeholder::make('explanation')
                            ->content('Ejemplo: Si Subcategoría = "ENTRADA" y Límite = 2, entonces el cliente puede ordenar máximo 2 productos de tipo ENTRADA en su pedido.')
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subcategory.name')
                    ->label('Subcategoría')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('max_products')
                    ->label('Límite Máximo')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state === 1 => 'danger',
                        $state === 2 => 'warning',
                        $state >= 3 => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (int $state): string => $state . ' producto' . ($state > 1 ? 's' : ''))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('subcategory')
                    ->relationship('subcategory', 'name')
                    ->label('Subcategoría'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Crear')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Agregar Límite de Subcategoría')
                    ->modalDescription('Define cuántos productos de una subcategoría puede ordenar el cliente')
                    ->modalSubmitActionLabel('Crear')
                    ->modalCancelActionLabel('Cancelar'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->modalHeading('Editar Límite de Subcategoría')
                    ->modalSubmitActionLabel('Guardar cambios')
                    ->modalCancelActionLabel('Cancelar'),

                Tables\Actions\DeleteAction::make()
                    ->label('Eliminar')
                    ->modalHeading('Eliminar Límite')
                    ->modalDescription('¿Estás seguro de que deseas eliminar este límite?')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->modalCancelActionLabel('Cancelar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Eliminar seleccionados')
                        ->modalHeading('Eliminar límites seleccionados')
                        ->modalDescription('¿Estás seguro de que deseas eliminar estos límites?')
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->modalCancelActionLabel('Cancelar'),
                ]),
            ])
            ->emptyStateHeading('No hay límites de subcategoría definidos')
            ->emptyStateDescription('Agrega límites para controlar cuántos productos de cada subcategoría puede ordenar el cliente')
            ->emptyStateIcon('heroicon-o-calculator');
    }
}
