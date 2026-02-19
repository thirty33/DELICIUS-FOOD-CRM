<?php

namespace App\Filament\Resources\SellerPortfolioResource\RelationManagers;

use App\Models\UserPortfolio;
use App\Services\Sellers\SellerPortfolioService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserPortfoliosRelationManager extends RelationManager
{
    protected static string $relationship = 'activeUserPortfolios';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Clientes (:count)', ['count' => $ownerRecord->activeUserPortfolios()->count()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.nickname')
                    ->label(__('Cliente'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.company.fantasy_name')
                    ->label(__('Empresa'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('assigned_at')
                    ->label(__('Asignado'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_order_at')
                    ->label(__('Primer pedido'))
                    ->dateTime('d/m/Y H:i')
                    ->default('—'),
                Tables\Columns\TextColumn::make('month_closed_at')
                    ->label(__('Cierre de mes'))
                    ->date('d/m/Y')
                    ->default('—'),
                Tables\Columns\TextColumn::make('previousPortfolio.name')
                    ->label(__('Cartera anterior'))
                    ->default('—'),
            ])
            ->defaultSort('assigned_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('history')
                    ->label(__('Historial'))
                    ->icon('heroicon-o-clock')
                    ->modalHeading(fn (UserPortfolio $record) => __('Historial de carteras: :name', ['name' => $record->user->nickname]))
                    ->modalContent(fn (UserPortfolio $record, SellerPortfolioService $service) => view(
                        'filament.seller-portfolio.client-history',
                        ['history' => $service->getClientPortfolioHistory($record->user_id)]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Cerrar')),
            ])
            ->headerActions([])
            ->bulkActions([]);
    }
}
