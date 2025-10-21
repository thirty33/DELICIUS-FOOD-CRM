<?php

namespace App\Filament\Resources\OrderRuleResource\RelationManagers;

use App\Models\Category;
use App\Models\Subcategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ExclusionsRelationManager extends RelationManager
{
    protected static string $relationship = 'exclusions';

    protected static ?string $title = 'Reglas de Exclusión';

    protected static ?string $recordTitleAttribute = 'source.name';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Configurar Exclusión')
                ->description('Define qué elementos no se pueden combinar en un pedido')
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            // ===== LEFT SIDE: SOURCE =====
                            Forms\Components\Section::make('Elemento Principal')
                                ->description('El elemento que tiene la restricción')
                                ->schema([
                                    Forms\Components\Radio::make('source_type')
                                        ->label('Tipo de Elemento')
                                        ->options([
                                            Subcategory::class => 'Subcategoría',
                                            Category::class => 'Categoría',
                                        ])
                                        ->default(Subcategory::class)
                                        ->reactive()
                                        ->required()
                                        ->columnSpanFull()
                                        ->inline()
                                        ->inlineLabel(false),

                                    Forms\Components\Select::make('source_id')
                                        ->label(fn (Get $get) =>
                                            $get('source_type') === Category::class
                                                ? 'Seleccionar Categoría'
                                                : 'Seleccionar Subcategoría'
                                        )
                                        ->options(fn (Get $get) =>
                                            $get('source_type') === Category::class
                                                ? Category::query()->where('is_active', true)->pluck('name', 'id')
                                                : Subcategory::pluck('name', 'id')
                                        )
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->columnSpanFull()
                                        ->helperText(fn (Get $get) =>
                                            $get('source_type') === Category::class
                                                ? 'Ejemplo: Platos de Fondo, Ensaladas, Postres'
                                                : 'Ejemplo: ENTRADA, PLATO DE FONDO, SANDWICH'
                                        ),
                                ])
                                ->columnSpan(1),

                            // ===== RIGHT SIDE: EXCLUDED =====
                            Forms\Components\Section::make('No Puede Combinarse Con')
                                ->description('El elemento que será excluido')
                                ->schema([
                                    Forms\Components\Radio::make('excluded_type')
                                        ->label('Tipo de Elemento')
                                        ->options([
                                            Subcategory::class => 'Subcategoría',
                                            Category::class => 'Categoría',
                                        ])
                                        ->default(Subcategory::class)
                                        ->reactive()
                                        ->required()
                                        ->columnSpanFull()
                                        ->inline()
                                        ->inlineLabel(false),

                                    Forms\Components\Select::make('excluded_id')
                                        ->label(fn (Get $get) =>
                                            $get('excluded_type') === Category::class
                                                ? 'Seleccionar Categoría'
                                                : 'Seleccionar Subcategoría'
                                        )
                                        ->options(fn (Get $get) =>
                                            $get('excluded_type') === Category::class
                                                ? Category::query()->where('is_active', true)->pluck('name', 'id')
                                                : Subcategory::pluck('name', 'id')
                                        )
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->columnSpanFull()
                                        ->helperText(fn (Get $get) =>
                                            $get('excluded_type') === Category::class
                                                ? 'Ejemplo: Platos de Fondo, Ensaladas, Postres'
                                                : 'Ejemplo: ENTRADA, PLATO DE FONDO, SANDWICH'
                                        ),
                                ])
                                ->columnSpan(1),
                        ]),
                ]),

            Forms\Components\Section::make('Ejemplos de Uso')
                ->schema([
                    Forms\Components\Placeholder::make('examples')
                        ->label('')
                        ->content(new HtmlString('
                            <ul class="list-disc pl-4 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                                <li>
                                    <strong>Subcategoría → Subcategoría:</strong>
                                    <span class="text-gray-500">"ENTRADA" no puede combinarse con "ENTRADA"</span>
                                    <br><span class="text-xs italic">Evita que el usuario ordene múltiples entradas</span>
                                </li>
                                <li>
                                    <strong>Subcategoría → Categoría:</strong>
                                    <span class="text-gray-500">"PLATO DE FONDO" no puede combinarse con categoría "Postres"</span>
                                    <br><span class="text-xs italic">Si el usuario selecciona un plato fuerte, no puede elegir postres</span>
                                </li>
                                <li>
                                    <strong>Categoría → Subcategoría:</strong>
                                    <span class="text-gray-500">Categoría "Ensaladas" no puede combinarse con "SANDWICH"</span>
                                    <br><span class="text-xs italic">Si el usuario elige una ensalada, no puede elegir productos con subcategoría SANDWICH</span>
                                </li>
                                <li>
                                    <strong>Categoría → Categoría:</strong>
                                    <span class="text-gray-500">Categoría "Bebidas" no puede combinarse con "Postres"</span>
                                    <br><span class="text-xs italic">Las bebidas y postres no pueden pedirse juntos</span>
                                </li>
                            </ul>
                        '))
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('source_display')
                    ->label('Elemento Principal')
                    ->badge()
                    ->color('primary')
                    ->getStateUsing(fn ($record) => $record->getSourceName())
                    ->description(fn ($record) => $record->getSourceTypeLabel())
                    ->searchable(false)
                    ->sortable(false),

                Tables\Columns\IconColumn::make('separator')
                    ->label('')
                    ->icon('heroicon-o-arrow-right')
                    ->color('gray')
                    ->alignCenter()
                    ->grow(false),

                Tables\Columns\TextColumn::make('excluded_display')
                    ->label('No Se Puede Combinar Con')
                    ->badge()
                    ->color('danger')
                    ->getStateUsing(fn ($record) => $record->getExcludedName())
                    ->description(fn ($record) => $record->getExcludedTypeLabel())
                    ->searchable(false)
                    ->sortable(false),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source_type')
                    ->label('Tipo de Elemento Principal')
                    ->options([
                        Subcategory::class => 'Subcategoría',
                        Category::class => 'Categoría',
                    ]),

                Tables\Filters\SelectFilter::make('excluded_type')
                    ->label('Tipo de Elemento Excluido')
                    ->options([
                        Subcategory::class => 'Subcategoría',
                        Category::class => 'Categoría',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Nueva Exclusión')
                    ->icon('heroicon-o-plus-circle')
                    ->modalWidth('6xl')
                    ->modalHeading('Crear Regla de Exclusión')
                    ->modalDescription('Define qué elementos no pueden ser combinados en un pedido')
                    ->modalSubmitActionLabel('Crear Regla')
                    ->modalCancelActionLabel('Cancelar'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading(fn ($record) => $record->getDescription())
                    ->modalWidth('4xl'),

                Tables\Actions\EditAction::make()
                    ->modalWidth('6xl')
                    ->modalHeading('Editar Regla de Exclusión')
                    ->modalSubmitActionLabel('Guardar Cambios')
                    ->modalCancelActionLabel('Cancelar'),

                Tables\Actions\DeleteAction::make()
                    ->modalHeading('Eliminar Regla de Exclusión')
                    ->modalDescription('¿Estás seguro de que deseas eliminar esta regla?')
                    ->modalSubmitActionLabel('Sí, Eliminar')
                    ->modalCancelActionLabel('Cancelar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Eliminar Seleccionados')
                        ->modalHeading('Eliminar Reglas Seleccionadas')
                        ->modalDescription('¿Estás seguro de que deseas eliminar estas reglas?')
                        ->modalSubmitActionLabel('Sí, Eliminar')
                        ->modalCancelActionLabel('Cancelar'),
                ]),
            ])
            ->emptyStateHeading('No hay reglas de exclusión')
            ->emptyStateDescription('Crea reglas para prevenir combinaciones no permitidas entre categorías y subcategorías')
            ->emptyStateIcon('heroicon-o-shield-exclamation')
            ->defaultSort('created_at', 'desc');
    }
}
