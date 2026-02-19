<?php

namespace App\Filament\Resources\SellerPortfolioResource\Pages;

use App\Filament\Resources\SellerPortfolioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSellerPortfolios extends ListRecords
{
    protected static string $resource = SellerPortfolioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
