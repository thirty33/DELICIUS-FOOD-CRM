<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebRegistrationRequestResource\Pages;
use App\Models\WebRegistrationRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WebRegistrationRequestResource extends Resource
{
    protected static ?string $model = WebRegistrationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Canales de Venta';

    protected static ?string $navigationLabel = 'Solicitudes de información';

    protected static ?string $modelLabel = 'Solicitud de información';

    protected static ?string $pluralModelLabel = 'Solicitudes de información';

    protected static ?int $navigationSort = 200;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información de contacto')
                    ->schema([
                        Forms\Components\TextInput::make('razon_social')
                            ->label('Razón Social')
                            ->disabled()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('rut')
                            ->label('RUT')
                            ->disabled()
                            ->maxLength(12),
                        Forms\Components\TextInput::make('nombre_fantasia')
                            ->label('Nombre Fantasía')
                            ->disabled()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('tipo_cliente')
                            ->label('Tipo de Cliente')
                            ->disabled()
                            ->maxLength(50),
                        Forms\Components\TextInput::make('giro')
                            ->label('Giro')
                            ->disabled()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('direccion')
                            ->label('Dirección')
                            ->disabled()
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('telefono')
                            ->label('Teléfono')
                            ->disabled()
                            ->maxLength(20),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->disabled()
                            ->email()
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Mensaje')
                    ->schema([
                        Forms\Components\Textarea::make('mensaje')
                            ->label('Mensaje del cliente')
                            ->disabled()
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Gestión')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Estado')
                            ->options([
                                'pending' => 'Pendiente',
                                'contacted' => 'Contactado',
                                'approved' => 'Aprobado',
                                'rejected' => 'Rechazado',
                            ])
                            ->required()
                            ->native(false),
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Notas del administrador')
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Información del registro')
                    ->schema([
                        Forms\Components\Placeholder::make('created_at')
                            ->label('Fecha de solicitud')
                            ->content(fn (WebRegistrationRequest $record): string => $record->created_at?->format('d/m/Y H:i:s') ?? '-'),
                        Forms\Components\Placeholder::make('updated_at')
                            ->label('Última actualización')
                            ->content(fn (WebRegistrationRequest $record): string => $record->updated_at?->format('d/m/Y H:i:s') ?? '-'),
                    ])
                    ->columns(2)
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('razon_social')
                    ->label('Razón Social')
                    ->searchable()
                    ->toggleable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('nombre_fantasia')
                    ->label('Nombre Fantasía')
                    ->searchable()
                    ->toggleable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('rut')
                    ->label('RUT')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tipo_cliente')
                    ->label('Tipo Cliente')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'contacted' => 'info',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pendiente',
                        'contacted' => 'Contactado',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha solicitud')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(
                fn (WebRegistrationRequest $record): string => static::getUrl('edit', ['record' => $record]),
            )
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'pending' => 'Pendiente',
                        'contacted' => 'Contactado',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                    ]),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListWebRegistrationRequests::route('/'),
            'edit' => Pages\EditWebRegistrationRequest::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
