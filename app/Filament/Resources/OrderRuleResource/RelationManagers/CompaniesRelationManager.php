<?php

namespace App\Filament\Resources\OrderRuleResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\Company;

class CompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static ?string $title = 'Empresas Asociadas';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Asociación de Empresa')
                    ->description('Asocia esta regla con empresas específicas. Si no hay empresas asociadas, la regla se aplica como general.')
                    ->schema([
                        Forms\Components\Select::make('id')
                            ->label('Seleccionar Empresa')
                            ->options(function () {
                                return Company::all()->mapWithKeys(function ($company) {
                                    $label = sprintf(
                                        '%s | %s | %s',
                                        $company->tax_id ?? 'Sin RUT',
                                        $company->company_code ?? 'Sin Código',
                                        $company->fantasy_name ?? $company->name ?? 'Sin Nombre'
                                    );
                                    return [$company->id => $label];
                                });
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Las reglas específicas de empresa tienen prioridad sobre las reglas generales')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Sistema de Prioridad')
                    ->schema([
                        Forms\Components\Placeholder::make('priority_explanation')
                            ->content(function () {
                                return '
                                    **Cómo Funciona la Prioridad:**
                                    1. **Reglas Específicas de Empresa** (con asociaciones) tienen precedencia
                                    2. **Reglas Generales** (sin asociaciones) se usan como alternativa
                                    3. Número menor de prioridad = Mayor prioridad (ej: 10 > 100)
                                ';
                            })
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre de la Empresa')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('fantasy_name')
                    ->label('Nombre de Fantasía')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->icon('heroicon-o-envelope')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pivot.created_at')
                    ->label('Asociada Desde')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Empresas Activas')
                    ->placeholder('Todas las empresas')
                    ->trueLabel('Solo activas')
                    ->falseLabel('Solo inactivas'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->orderBy('fantasy_name'))
                    ->recordSelectSearchColumns(['tax_id', 'company_code', 'fantasy_name', 'name'])
                    ->recordTitle(fn ($record) => sprintf(
                        '%s | %s | %s',
                        $record->tax_id ?? 'Sin RUT',
                        $record->company_code ?? 'Sin Código',
                        $record->fantasy_name ?? $record->name ?? 'Sin Nombre'
                    ))
                    ->label('Asociar Empresa')
                    ->icon('heroicon-o-link')
                    ->modalHeading('Asociar Empresa con Regla')
                    ->modalDescription('Las reglas específicas de empresa tendrán precedencia sobre las reglas generales para los usuarios de esta empresa.')
                    ->modalSubmitActionLabel('Asociar')
                    ->modalCancelActionLabel('Cancelar')
                    ->color('success'),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->label('Remover')
                    ->icon('heroicon-o-trash')
                    ->modalHeading('Remover Empresa')
                    ->modalDescription('¿Estás seguro de que deseas remover esta empresa de la regla?')
                    ->modalSubmitActionLabel('Sí, remover')
                    ->modalCancelActionLabel('Cancelar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make()
                        ->label('Remover Seleccionadas')
                        ->modalHeading('Remover Empresas Seleccionadas')
                        ->modalDescription('¿Estás seguro de que deseas remover estas empresas de la regla?')
                        ->modalSubmitActionLabel('Sí, remover')
                        ->modalCancelActionLabel('Cancelar'),
                ]),
            ])
            ->emptyStateHeading('No hay empresas asociadas')
            ->emptyStateDescription('Esta regla se aplica como general. Asocia empresas para hacerla específica con mayor prioridad.')
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->emptyStateActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->orderBy('fantasy_name'))
                    ->recordSelectSearchColumns(['tax_id', 'company_code', 'fantasy_name', 'name'])
                    ->recordTitle(fn ($record) => sprintf(
                        '%s | %s | %s',
                        $record->tax_id ?? 'Sin RUT',
                        $record->company_code ?? 'Sin Código',
                        $record->fantasy_name ?? $record->name ?? 'Sin Nombre'
                    ))
                    ->label('Asociar Primera Empresa')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Asociar Empresa con Regla')
                    ->modalDescription('Las reglas específicas de empresa tendrán precedencia sobre las reglas generales para los usuarios de esta empresa.')
                    ->modalSubmitActionLabel('Asociar')
                    ->modalCancelActionLabel('Cancelar')
                    ->color('primary'),
            ]);
    }
}
