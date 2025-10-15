<?php

namespace App\Filament\Resources\OrderRuleResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\Subcategory;

class SubcategoryExclusionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subcategoryExclusions';

    protected static ?string $title = 'Reglas de Exclusión de Subcategorías';

    protected static ?string $recordTitleAttribute = 'subcategory.name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Regla de Exclusión')
                    ->description('Define qué subcategorías no se pueden combinar')
                    ->schema([
                        Forms\Components\Select::make('subcategory_id')
                            ->label('Subcategoría Principal')
                            ->options(Subcategory::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('La subcategoría que tiene restricciones')
                            ->columnSpan(1),

                        Forms\Components\Select::make('excluded_subcategory_id')
                            ->label('Subcategoría Excluida')
                            ->options(Subcategory::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('La subcategoría que no se puede combinar con la principal')
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Explicación de la Regla')
                    ->schema([
                        Forms\Components\Placeholder::make('explanation')
                            ->content('Ejemplo: Si Principal = "ENTRADA" y Excluida = "ENTRADA", entonces los clientes no pueden ordenar múltiples productos con la subcategoría ENTRADA.')
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
                    ->label('Subcategoría Principal')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('excludedSubcategory.name')
                    ->label('No Se Puede Combinar Con')
                    ->badge()
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->searchable()
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
                    ->label('Subcategoría Principal'),

                Tables\Filters\SelectFilter::make('excludedSubcategory')
                    ->relationship('excludedSubcategory', 'name')
                    ->label('Subcategoría Excluida'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Crear')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Agregar Regla de Exclusión')
                    ->modalDescription('Define qué subcategorías no se pueden combinar en los pedidos')
                    ->modalSubmitActionLabel('Crear')
                    ->modalCancelActionLabel('Cancelar'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->modalHeading('Editar Regla de Exclusión')
                    ->modalSubmitActionLabel('Guardar cambios')
                    ->modalCancelActionLabel('Cancelar'),
                Tables\Actions\DeleteAction::make()
                    ->label('Eliminar')
                    ->modalHeading('Eliminar Regla de Exclusión')
                    ->modalDescription('¿Estás seguro de que deseas eliminar esta regla?')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->modalCancelActionLabel('Cancelar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Eliminar seleccionados')
                        ->modalHeading('Eliminar reglas seleccionadas')
                        ->modalDescription('¿Estás seguro de que deseas eliminar estas reglas?')
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->modalCancelActionLabel('Cancelar'),
                ]),
            ])
            ->emptyStateHeading('No hay reglas de exclusión definidas')
            ->emptyStateDescription('Agrega reglas de exclusión para prevenir ciertas combinaciones de subcategorías en los pedidos')
            ->emptyStateIcon('heroicon-o-shield-exclamation');
    }
}
