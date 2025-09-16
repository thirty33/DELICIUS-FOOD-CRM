<?php

namespace App\Filament\Resources\PriceListResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;

class CompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Empresas asociadas a la lista :name', ['name' => $ownerRecord->name]);
    }

    protected static function getRecordLabel(): ?string
    {
        return __('Empresa');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('email'),
                Tables\Columns\TextColumn::make('phone_number'),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('tax_id')
                    ->label('RUT'),
                Tables\Columns\TextColumn::make('fantasy_name'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
                Tables\Actions\AssociateAction::make()
                    ->label('Añadir'),
            ])
            ->actions([
                // Tables\Actions\EditAction::make(),
                Tables\Actions\DissociateAction::make()
                    ->label('Eliminar'),
                // Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DissociateBulkAction::make()
                        ->label('Eliminar seleccionados'),
                    // Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
