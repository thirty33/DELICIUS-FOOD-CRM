<?php

namespace App\Filament\Resources;

use App\Enums\ValueTypeEnum;
use App\Filament\Resources\ParameterResource\Pages;
use App\Filament\Resources\ParameterResource\RelationManagers;
use App\Models\Parameter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ParameterResource extends Resource
{
    protected static ?string $model = Parameter::class;

    protected static ?string $navigationIcon = 'iconsax-bro-receipt-item';

    protected static ?int $navigationSort = 100;

    public static function getNavigationGroup(): ?string
    {
        return __('Seguridad');
    }

    public static function getLabel(): ?string
    {
        return __('Parámetro');
    }

    public static function getNavigationLabel(): string
    {
        return __('Configuración');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Parámetros');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('Nombre'))
                    ->required()
                    ->maxLength(255)
                    ->disabled(),
                Forms\Components\Textarea::make('description')
                    ->label(__('Descripción'))
                    ->columnSpanFull(),
                Forms\Components\Select::make('value_type')
                    ->label(__('Tipo de valor'))
                    ->options(ValueTypeEnum::options())
                    ->required(),
                Forms\Components\Textarea::make('value')
                    ->label(__('Valor'))
                    ->columnSpanFull()
                    ->helperText(__('Ingrese el valor según el tipo seleccionado')),
                Forms\Components\Toggle::make('active')
                    ->label(__('Activo'))
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('value_type')
                    ->label(__('Tipo de valor'))
                    ->formatStateUsing(fn (string $state): string => ValueTypeEnum::from($state)->getLabel())
                    ->searchable(),
                Tables\Columns\TextColumn::make('value')
                    ->label(__('Valor'))
                    ->limit(30),
                Tables\Columns\IconColumn::make('active')
                    ->label(__('Activo'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado el'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Actualizado el'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('value_type')
                    ->label(__('Tipo de valor'))
                    ->options(ValueTypeEnum::options()),
                Tables\Filters\Filter::make('active')
                    ->label(__('Activos'))
                    ->query(fn (Builder $query): Builder => $query->where('active', true))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(__('Editar')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label(__('Eliminar seleccionados')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParameters::route('/'),
            'create' => Pages\CreateParameter::route('/create'),
            'edit' => Pages\EditParameter::route('/{record}/edit'),
        ];
    }
}