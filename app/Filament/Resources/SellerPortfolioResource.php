<?php

namespace App\Filament\Resources;

use App\Enums\PortfolioCategory;
use App\Filament\Resources\SellerPortfolioResource\Pages;
use App\Filament\Resources\SellerPortfolioResource\RelationManagers\UserPortfoliosRelationManager;
use App\Models\SellerPortfolio;
use App\Models\User;
use App\Services\Sellers\SellerPortfolioService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SellerPortfolioResource extends Resource
{
    protected static ?string $model = SellerPortfolio::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): ?string
    {
        return 'Canales de Venta';
    }

    public static function getLabel(): ?string
    {
        return __('Cartera');
    }

    public static function getNavigationLabel(): string
    {
        return __('Carteras');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Carteras');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('Nombre'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('category')
                                    ->label(__('Categoría'))
                                    ->options(PortfolioCategory::class)
                                    ->required(),
                                Forms\Components\Select::make('seller_id')
                                    ->label(__('Vendedor'))
                                    ->options(fn (SellerPortfolioService $service) => $service
                                        ->getSellersForSelect()
                                        ->mapWithKeys(fn (User $user) => [$user->id => $user->nickname])
                                    )
                                    ->searchable()
                                    ->required(),
                            ]),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('successor_portfolio_id')
                                    ->label(__('Cartera sucesora'))
                                    ->options(fn (SellerPortfolioService $service, ?SellerPortfolio $record) => $service
                                        ->getPortfoliosForSelect($record?->id)
                                        ->mapWithKeys(fn (SellerPortfolio $p) => [$p->id => $p->name.' ('.$p->seller->nickname.')'])
                                    )
                                    ->searchable()
                                    ->nullable()
                                    ->placeholder(__('Sin cartera sucesora')),
                                Forms\Components\Toggle::make('is_default')
                                    ->label(__('Por defecto'))
                                    ->default(false),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label(__('Categoría'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('seller.nickname')
                    ->label(__('Vendedor'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('successorPortfolio.name')
                    ->label(__('Cartera sucesora'))
                    ->default('—'),
                Tables\Columns\IconColumn::make('is_default')
                    ->label(__('Por defecto'))
                    ->boolean()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('active_user_portfolios_count')
                    ->label(__('Clientes'))
                    ->counts('activeUserPortfolios')
                    ->alignCenter(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label(__('Clientes'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (SellerPortfolio $record) => __('Clientes en: :name', ['name' => $record->name]))
                    ->modalContent(fn (SellerPortfolio $record, SellerPortfolioService $service) => view(
                        'filament.seller-portfolio.preview-clients',
                        ['clients' => $service->getActiveClientsForPortfolio($record->id)]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Cerrar')),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            UserPortfoliosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSellerPortfolios::route('/'),
            'create' => Pages\CreateSellerPortfolio::route('/create'),
            'edit' => Pages\EditSellerPortfolio::route('/{record}/edit'),
        ];
    }
}
