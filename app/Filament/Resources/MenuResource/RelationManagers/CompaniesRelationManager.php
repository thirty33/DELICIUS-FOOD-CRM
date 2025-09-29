<?php

namespace App\Filament\Resources\MenuResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static ?string $title = 'Empresas asociadas';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('Nombre'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('tax_id')
                    ->label(__('RUT'))
                    ->maxLength(255),
                Forms\Components\TextInput::make('company_name')
                    ->label(__('Razón social'))
                    ->maxLength(255),
                Forms\Components\TextInput::make('fantasy_name')
                    ->label(__('Nombre de fantasía'))
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tax_id')
                    ->label(__('RUT'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('company_name')
                    ->label(__('Razón social'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('fantasy_name')
                    ->label(__('Nombre de fantasía'))
                    ->searchable(),
                Tables\Columns\ToggleColumn::make('active')
                    ->label(__('Activo'))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label(__('Activo'))
                    ->boolean()
                    ->trueLabel(__('Solo activos'))
                    ->falseLabel(__('Solo inactivos'))
                    ->native(false),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect(),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
