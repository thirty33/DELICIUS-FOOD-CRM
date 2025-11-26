<?php

namespace App\Filament\Resources\NutritionalInformationResource\RelationManagers;

use App\Enums\NutritionalValueType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class NutritionalValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'nutritionalValues';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Valores Nutricionales');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->label(__('Tipo de Valor'))
                    ->options(NutritionalValueType::options())
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('value')
                    ->label(__('Valor'))
                    ->required()
                    ->numeric()
                    ->default(0.00),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state instanceof NutritionalValueType ? $state->label() : $state),
                Tables\Columns\TextColumn::make('value')
                    ->label(__('Valor'))
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($state, $record) => $record->getFormattedValue()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
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
            ->defaultSort('type');
    }
}
