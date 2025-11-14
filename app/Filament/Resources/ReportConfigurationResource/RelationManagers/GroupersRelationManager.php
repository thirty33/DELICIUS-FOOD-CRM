<?php

namespace App\Filament\Resources\ReportConfigurationResource\RelationManagers;

use App\Models\Company;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class GroupersRelationManager extends RelationManager
{
    protected static string $relationship = 'groupers';

    protected static ?string $title = 'Agrupadores';

    protected static ?string $modelLabel = 'agrupador';

    protected static ?string $pluralModelLabel = 'agrupadores';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Nombre del agrupador (ej: "CAFETERIA ALMA TERRA")'),

                Forms\Components\TextInput::make('code')
                    ->label('Código')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Código único para este agrupador'),

                Forms\Components\TextInput::make('display_order')
                    ->label('Orden de Visualización')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->helperText('Orden en el que aparecerá en los reportes (menor = primero)'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true)
                    ->helperText('Solo los agrupadores activos aparecen en los reportes'),

                Forms\Components\Select::make('companies')
                    ->label('Empresas')
                    ->multiple()
                    ->relationship('companies', 'fantasy_name')
                    ->getOptionLabelFromRecordUsing(fn (Company $record) =>
                        "{$record->company_code} - {$record->fantasy_name}")
                    ->searchable(['company_code', 'fantasy_name', 'name'])
                    ->preload()
                    ->helperText('Seleccione las empresas que pertenecen a este agrupador'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('display_order')
                    ->label('Orden')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('companies_count')
                    ->label('Empresas')
                    ->counts('companies')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activo')
                    ->placeholder('Todos')
                    ->trueLabel('Sí')
                    ->falseLabel('No'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Crear Agrupador'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('display_order', 'asc');
    }
}
