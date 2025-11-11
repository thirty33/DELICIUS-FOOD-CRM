<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;
use Pelmered\FilamentMoneyField\Forms\Components\MoneyInput;
use Pelmered\FilamentMoneyField\Tables\Columns\MoneyColumn;

class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Sucursales de la empresa :name', ['name' => $ownerRecord->name]);
    }

    protected static function getRecordLabel(): ?string
    {
        return __('Sucursal de empresa');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\TextInput::make('branch_code')
                            ->required()
                            ->minLength(2)
                            ->maxLength(50)
                            ->label(__('Código'))
                            ->columns(1),
                        Forms\Components\TextInput::make('fantasy_name')
                            ->autofocus()
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Nombre de fantasía'))
                            ->columns(1),
                        Forms\Components\TextInput::make('address')
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Dirección'))
                            ->columns(1),
                        Forms\Components\TextInput::make('shipping_address')
                            ->label(__('Dirección de Despacho'))
                            ->nullable()
                            ->columns(1),
                        Forms\Components\TextInput::make('contact_name')
                            ->label(__('Nombre contacto'))
                            ->nullable()
                            ->columns(1),
                        Forms\Components\TextInput::make('contact_last_name')
                            ->label(__('Apellido de contacto'))
                            ->nullable()
                            ->columns(1),
                        Forms\Components\TextInput::make('contact_phone_number')
                            ->label(__('Número de contacto'))
                            ->nullable()
                            ->columns(1),
                        MoneyInput::make('min_price_order')
                            ->label(__('Precio pedido mínimo'))
                            ->required()
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2),
                    ])->columns(2)
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('branch_code')
                    ->label(__('Código'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fantasy_name')
                    ->label(__('Nombre de fantasía'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('address')
                    ->label(__('Dirección'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipping_address')
                    ->label(__('Dirección de despacho'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('contact_name')
                    ->label(__('Contacto'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('contact_phone_number')
                    ->label(__('Número de teléfono'))
                    ->searchable()
                    ->sortable(),
                MoneyColumn::make('min_price_order')
                    ->label(__('Precio pedido mínimo'))
                    ->currency('USD')
                    ->locale('en_US'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                // Tables\Actions\AttachAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Tables\Actions\DetachAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Tables\Actions\DetachBulkAction::make(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }
}
