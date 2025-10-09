<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationResource\Pages;
use App\Models\Integration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IntegrationResource extends Resource
{
    protected static ?string $model = Integration::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'Integración';

    protected static ?string $pluralModelLabel = 'Integraciones';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('name')
                    ->required()
                    ->options(Integration::getNames())
                    ->label('Nombre'),
                Forms\Components\TextInput::make('url')
                    ->required()
                    ->maxLength(255)
                    ->url()
                    ->label('URL Producción'),
                Forms\Components\TextInput::make('url_test')
                    ->required()
                    ->maxLength(255)
                    ->url()
                    ->label('URL Pruebas'),
                Forms\Components\Select::make('type')
                    ->required()
                    ->options([
                        Integration::TYPE_BILLING => 'Facturación',
                        Integration::TYPE_PAYMENT_GATEWAY => 'Pasarela de Pago',
                    ])
                    ->label('Tipo'),
                Forms\Components\Toggle::make('production')
                    ->label('Modo Producción')
                    ->helperText('Activar para usar la URL de producción'),
                Forms\Components\Toggle::make('active')
                    ->label('Activa')
                    ->helperText('Solo puede haber una integración activa por tipo'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->formatStateUsing(fn (string $state): string => Integration::getNames()[$state] ?? $state)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Integration::getTypes()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Integration::TYPE_BILLING => 'primary',
                        Integration::TYPE_PAYMENT_GATEWAY => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL Producción')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('url_test')
                    ->label('URL Pruebas')
                    ->searchable()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('production')
                    ->label('Producción')
                    ->boolean(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),
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
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Integration::getTypes()),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Activa'),
                Tables\Filters\TernaryFilter::make('production')
                    ->label('Producción'),
                Tables\Filters\TrashedFilter::make()
                    ->label('Eliminadas'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
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
            'index' => Pages\ListIntegrations::route('/'),
            'create' => Pages\CreateIntegration::route('/create'),
            'edit' => Pages\EditIntegration::route('/{record}/edit'),
        ];
    }
}
