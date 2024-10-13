<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use App\Models\User;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Pelmered\FilamentMoneyField\Forms\Components\MoneyInput;
use Pelmered\FilamentMoneyField\Tables\Columns\MoneyColumn;
use Filament\Forms\Components\Hidden;
use Closure;
use Filament\Forms\Get;


class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'iconsax-bro-receipt-item';

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
                        Hidden::make('id'),
                        Forms\Components\Select::make('user_id')
                            ->options(User::customers()->pluck('name', 'id'))
                            ->label(__('Cliente'))
                            ->searchable()
                            ->disabledOn('edit'),
                        MoneyInput::make('total')
                            ->label(__('Total'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->rules([
                                fn(Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                    $order = Order::find($get('id'));
                                    if ($order && $order->total < $order->price_list_min) {
                                        $fail(__("El total del pedido debe ser igual o mayor al precio mínimo de la lista de precios."));
                                    }
                                },
                            ])
                            ->disabled(),
                        MoneyInput::make('price_list_min')
                            ->label(__('Monto mínimo'))
                            ->currency('USD')
                            ->locale('en_US')
                            ->minValue(0)
                            ->decimals(2)
                            ->disabled(),
                        Forms\Components\Select::make('status')
                            ->label(__('Estado'))
                            ->options([
                                'pending' => 'Pendiente',
                                'processing' => 'En proceso',
                                'completed' => 'Completado',
                                'declined' => 'Rechazado',
                            ])
                            ->default('pending')
                            ->disabledOn('create'),
                        Forms\Components\DateTimePicker::make('created_at')
                            ->label(__('Fecha de creación'))
                            ->readOnly(),
                        Forms\Components\Select::make('branch_id')
                            ->label(__('Direccíon de despacho'))
                            ->relationship('user.company.branches', 'shipping_address')
                            ->disabledOn('create'),
                        Forms\Components\Textarea::make('alternative_address')
                            ->minLength(2)
                            ->maxLength(200)
                            ->label(__('Otra dirección'))
                            ->columnSpanFull()
                            ->disabledOn('create'),
                        Forms\Components\DateTimePicker::make('dispatch_date')
                            ->label(__('Fecha de despacho'))
                            ->disabledOn('create'),
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
                MoneyColumn::make('total')
                    ->label(__('Total'))
                    ->currency('USD')
                    ->locale('en_US'),
                Tables\Columns\TextColumn::make('total_products')
                    ->label(__('Total productos'))
                    ->state(fn(Model $order) => $order->orderLines->sum('quantity')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Estado'))
                    ->sortable()
                    ->color(fn(string $state): string => match ($state) {
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
