<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Almacén');
    }

    public static function getLabel(): ?string
    {
        return __('Pedido');
    }

    public static function getNavigationLabel(): string
    {
        return __('Pedidos');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->options(User::customers()->pluck('name', 'id'))
                            ->label(__('Cliente'))
                            ->searchable(),
                        Forms\Components\TextInput::make('total')
                            ->label(__('Total'))
                            ->suffix('€'),
                        Forms\Components\Select::make('status')
                            ->label(__('Estado'))
                            ->options([
                                'pending' => 'Pendiente',
                                'processing' => 'En proceso',
                                'completed' => 'Completado',
                                'declined' => 'Rechazado',
                            ])
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->searchable()
                    ->prefix('#')
                    ->suffix('#'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('Cliente'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('total')
                    ->label(__('Total'))
                    ->searchable()
                    ->sortable()
                    ->suffix('€'),
                Tables\Columns\TextColumn::make('total_products')
                    ->label(__('Total productos'))
                    ->state(fn (Model $order) => $order->orderLines->sum('quantity')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Estado'))
                    ->sortable()
                    ->color(fn (string $state):string => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        'declined' => 'danger',
                    })
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label(__('Cliente'))
                    ->options(User::customers()->pluck('name', 'id'))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options([
                        'pending' => 'Pendiente',
                        'processing' => 'En proceso',
                        'completed' => 'Completado',
                        'declined' => 'Rechazado',
                    ])
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->emptyStateDescription(__('No hay pedidos actualmente'));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\OrderLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
