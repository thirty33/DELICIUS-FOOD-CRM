<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;

class IngredientsRelationManager extends RelationManager
{
    protected static string $relationship = 'ingredients';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Ingredientes del producto :name', ['name' => $ownerRecord->name]);
    }

    protected static function getRecordLabel(): ?string
    {
        return __('Ingrediente');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('descriptive_text')
                    ->label(_('Texto Descriptivo'))
                    ->required()
                    ->maxLength(100),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('descriptive_text')
            ->columns([
                Tables\Columns\TextColumn::make('descriptive_text')
                    ->label(_('Texto Descriptivo')),
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
            ]);
    }
}
