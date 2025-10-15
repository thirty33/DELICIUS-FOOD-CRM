<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderRuleResource\Pages;
use App\Filament\Resources\OrderRuleResource\RelationManagers;
use App\Models\OrderRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderRuleResource extends Resource
{
    protected static ?string $model = OrderRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?string $modelLabel = 'Regla de Pedido';

    protected static ?string $pluralModelLabel = 'Reglas de Pedidos';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información Básica')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre de la Regla')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2)
                            ->helperText('Un nombre descriptivo para esta regla de pedido'),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Descripción detallada de lo que hace esta regla'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Configuración de la Regla')
                    ->schema([
                        Forms\Components\Select::make('rule_type')
                            ->label('Tipo de Regla')
                            ->required()
                            ->options([
                                'subcategory_exclusion' => 'Exclusión de Subcategorías',
                                'mandatory_category' => 'Categoría Obligatoria',
                                'product_limit' => 'Límite de Productos',
                                'product_limit_per_subcategory' => 'Límite de Productos por Subcategoría',
                            ])
                            ->default('subcategory_exclusion')
                            ->helperText('Tipo de regla de validación a aplicar'),

                        Forms\Components\TextInput::make('priority')
                            ->label('Prioridad')
                            ->required()
                            ->numeric()
                            ->default(100)
                            ->minValue(1)
                            ->maxValue(1000)
                            ->helperText('Número menor = mayor prioridad (ej: 10 vence a 100)')
                            ->suffix('(1-1000)'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Rol y Permiso')
                    ->schema([
                        Forms\Components\Select::make('role_id')
                            ->label('Rol')
                            ->relationship('role', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('A qué rol se aplica esta regla'),

                        Forms\Components\Select::make('permission_id')
                            ->label('Permiso')
                            ->relationship('permission', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('A qué permiso se aplica esta regla'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Estado')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Activa')
                            ->required()
                            ->default(true)
                            ->helperText('Solo las reglas activas se aplican'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre de la Regla')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('rule_type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'subcategory_exclusion' => 'success',
                        'mandatory_category' => 'warning',
                        'product_limit' => 'info',
                        'product_limit_per_subcategory' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'subcategory_exclusion' => 'Exclusión de Subcategorías',
                        'mandatory_category' => 'Categoría Obligatoria',
                        'product_limit' => 'Límite de Productos',
                        'product_limit_per_subcategory' => 'Límite por Subcategoría',
                        default => str($state)->replace('_', ' ')->title(),
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('role.name')
                    ->label('Rol')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('permission.name')
                    ->label('Permiso')
                    ->badge()
                    ->color('secondary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridad')
                    ->numeric()
                    ->sortable()
                    ->description('Menor = Mayor Prioridad')
                    ->color(fn (int $state): string => match (true) {
                        $state < 50 => 'danger',
                        $state < 100 => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('companies_count')
                    ->counts('companies')
                    ->label('Empresas')
                    ->badge()
                    ->color('info')
                    ->description('Empresas asociadas'),

                Tables\Columns\TextColumn::make('subcategory_exclusions_count')
                    ->counts('subcategoryExclusions')
                    ->label('Exclusiones')
                    ->badge()
                    ->color('warning')
                    ->description('Reglas de exclusión'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('priority', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('rule_type')
                    ->label('Tipo de Regla')
                    ->options([
                        'subcategory_exclusion' => 'Exclusión de Subcategorías',
                        'mandatory_category' => 'Categoría Obligatoria',
                        'product_limit' => 'Límite de Productos',
                        'product_limit_per_subcategory' => 'Límite por Subcategoría',
                    ]),

                Tables\Filters\SelectFilter::make('role')
                    ->relationship('role', 'name')
                    ->label('Rol'),

                Tables\Filters\SelectFilter::make('permission')
                    ->relationship('permission', 'name')
                    ->label('Permiso'),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activa')
                    ->placeholder('Todas las reglas')
                    ->trueLabel('Solo activas')
                    ->falseLabel('Solo inactivas'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver'),
                Tables\Actions\EditAction::make()
                    ->label('Editar'),
                Tables\Actions\DeleteAction::make()
                    ->label('Eliminar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Eliminar seleccionados'),
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(fn ($records) => $records->each->update(['is_active' => true]))
                        ->requiresConfirmation()
                        ->modalHeading('Activar reglas seleccionadas')
                        ->modalDescription('¿Estás seguro de que deseas activar estas reglas?')
                        ->modalSubmitActionLabel('Sí, activar')
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Desactivar')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->action(fn ($records) => $records->each->update(['is_active' => false]))
                        ->requiresConfirmation()
                        ->modalHeading('Desactivar reglas seleccionadas')
                        ->modalDescription('¿Estás seguro de que deseas desactivar estas reglas?')
                        ->modalSubmitActionLabel('Sí, desactivar')
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SubcategoryExclusionsRelationManager::class,
            RelationManagers\SubcategoryLimitsRelationManager::class,
            RelationManagers\CompaniesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrderRules::route('/'),
            'create' => Pages\CreateOrderRule::route('/create'),
            'edit' => Pages\EditOrderRule::route('/{record}/edit'),
        ];
    }
}
