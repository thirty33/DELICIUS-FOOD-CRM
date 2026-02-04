<?php

namespace App\Filament\Resources;

use App\Enums\IntegrationName;
use App\Filament\Resources\BillingProcessResource\Pages;
use App\Filament\Resources\BillingProcessResource\RelationManagers\AttemptsRelationManager;
use App\Models\BillingProcess;
use App\Models\Integration;
use App\Repositories\BillingProcessRepository;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BillingProcessResource extends Resource
{
    protected static ?string $model = BillingProcess::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Facturación';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Proceso de Facturación';

    protected static ?string $pluralModelLabel = 'Procesos de Facturación';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('order_id')
                    ->relationship('order', 'id')
                    ->required()
                    ->searchable()
                    ->label('Pedido')
                    ->disabled(),
                Forms\Components\Placeholder::make('status')
                    ->label('Estado')
                    ->content(function ($record) {
                        if (!$record) {
                            return null;
                        }

                        $statusLabels = [
                            BillingProcess::STATUS_PENDING => 'Pendiente',
                            BillingProcess::STATUS_SUCCESS => 'Exitoso',
                            BillingProcess::STATUS_FAILED => 'Errado',
                        ];

                        $statusColors = [
                            BillingProcess::STATUS_PENDING => 'warning',
                            BillingProcess::STATUS_SUCCESS => 'success',
                            BillingProcess::STATUS_FAILED => 'danger',
                        ];

                        $label = $statusLabels[$record->status] ?? $record->status;
                        $color = $statusColors[$record->status] ?? 'gray';

                        return new \Illuminate\Support\HtmlString(
                            '<span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-w-[theme(spacing.6)] py-1 fi-color-' . $color . ' fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30" style="--c-50:var(--' . $color . '-50);--c-400:var(--' . $color . '-400);--c-600:var(--' . $color . '-600);">'
                            . $label .
                            '</span>'
                        );
                    }),
                Forms\Components\Select::make('responsible_id')
                    ->relationship('responsible', 'name')
                    ->required()
                    ->searchable()
                    ->label('Responsable')
                    ->disabled(),
                Forms\Components\Select::make('integration_id')
                    ->relationship('integration', 'name')
                    ->required()
                    ->label('Integración')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order.id')
                    ->label('Pedido')
                    ->numeric()
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        BillingProcess::STATUS_PENDING => 'Pendiente',
                        BillingProcess::STATUS_SUCCESS => 'Exitoso',
                        BillingProcess::STATUS_FAILED => 'Errado',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        BillingProcess::STATUS_PENDING => 'warning',
                        BillingProcess::STATUS_SUCCESS => 'success',
                        BillingProcess::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->label('Responsable')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('integration.name')
                    ->label('Integración')
                    ->formatStateUsing(fn (string $state): string => IntegrationName::tryFrom($state)?->getLabel() ?? $state)
                    ->sortable(),
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
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        BillingProcess::STATUS_PENDING => 'Pendiente',
                        BillingProcess::STATUS_SUCCESS => 'Exitoso',
                        BillingProcess::STATUS_FAILED => 'Errado',
                    ]),
                Tables\Filters\TrashedFilter::make()
                    ->label('Eliminados'),
            ])
            ->actions([
                Tables\Actions\Action::make('facturar')
                    ->label('Facturar')
                    ->icon('heroicon-o-document-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Procesar Facturación')
                    ->modalDescription('¿Estás seguro de que deseas procesar la facturación para este pedido? Se creará un nuevo intento de facturación.')
                    ->action(function (BillingProcess $record) {
                        $repository = new BillingProcessRepository();
                        $result = $repository->processBilling($record);

                        if ($result['success']) {
                            Notification::make()
                                ->title('Facturación procesada')
                                ->body($result['message'])
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Error al procesar facturación')
                                ->body($result['message'])
                                ->danger()
                                ->send();
                        }
                    }),
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
            AttemptsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBillingProcesses::route('/'),
            'edit' => Pages\EditBillingProcess::route('/{record}/edit'),
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
