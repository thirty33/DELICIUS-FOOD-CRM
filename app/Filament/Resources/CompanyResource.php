<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource\RelationManagers;
use App\Models\Company;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Checkbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationGroup(): ?string
    {
        return __('Administración');
    }

    public static function getLabel(): ?string
    {
        return __('Empresa');
    }

    public static function getNavigationLabel(): string
    {
        return __('Empresas');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('logo')
                    ->label(__('Logo'))
                    ->image()
                    ->maxSize(4096)
                    ->placeholder(__('Logo de la empresa'))
                    ->columnSpanFull(),
                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->autofocus()
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->unique(static::getModel(), 'name', ignoreRecord: true)
                            ->label(__('Nombre'))
                            ->columns(1),
                        Forms\Components\TextInput::make('address')
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Dirección'))
                            ->columns(1),
                        Forms\Components\TextInput::make('email')
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Email'))
                            ->columns(1),
                        Forms\Components\TextInput::make('phone_number')
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Número de teléfono'))
                            ->columns(1),
                        Forms\Components\TextInput::make('website')
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Website'))
                            ->columns(1),
                        Forms\Components\TextInput::make('registration_number')
                            ->required()
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Número de registro'))
                            ->columns(1),
                        Checkbox::make('active')
                            ->label(__('Activo'))
                            ->columns(1),
                    ])->columns(3),
                Forms\Components\Textarea::make('description')
                    ->required()
                    ->minLength(2)
                    ->maxLength(200)
                    ->label(__('Descripción'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label(__('Imagen')),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Creado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Actualizado'))
                    ->sortable()
                    ->date('d/m/Y H:i'),
                Tables\Columns\ToggleColumn::make('active')
                    ->label(__('Activo'))
                    ->sortable(),
            ])
            ->filters([
                //
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
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->emptyStateDescription(__('No hay empresas creadas'));
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
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
