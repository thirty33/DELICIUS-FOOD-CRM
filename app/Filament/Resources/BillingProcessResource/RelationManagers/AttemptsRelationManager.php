<?php

namespace App\Filament\Resources\BillingProcessResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'attempts';

    protected static ?string $title = 'Intentos de Facturación';

    protected static ?string $modelLabel = 'Intento';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Textarea::make('request_body')
                    ->required()
                    ->rows(5)
                    ->label('Cuerpo de la Petición (JSON)'),
                Forms\Components\Textarea::make('response_body')
                    ->rows(5)
                    ->label('Cuerpo de la Respuesta (JSON)'),
                Forms\Components\TextInput::make('response_status')
                    ->numeric()
                    ->label('Estado HTTP de la Respuesta'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('response_status')
                    ->label('Estado HTTP')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 400 && $state < 500 => 'warning',
                        $state >= 500 => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('request_body')
                    ->label('Petición')
                    ->limit(50)
                    ->tooltip(fn ($state): string => $state),
                Tables\Columns\TextColumn::make('response_body')
                    ->label('Respuesta')
                    ->limit(50)
                    ->tooltip(fn ($state): ?string => $state),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make()
                    ->label('Eliminados'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('refresh')
                    ->label('Recargar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(fn () => null),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver'),
                Tables\Actions\DeleteAction::make()
                    ->label('Eliminar'),
                Tables\Actions\ForceDeleteAction::make()
                    ->label('Eliminar Permanentemente'),
                Tables\Actions\RestoreAction::make()
                    ->label('Restaurar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }
}
