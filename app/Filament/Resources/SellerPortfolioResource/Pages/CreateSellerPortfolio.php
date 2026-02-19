<?php

namespace App\Filament\Resources\SellerPortfolioResource\Pages;

use App\Actions\Sellers\CreateSellerPortfolioAction;
use App\Filament\Resources\SellerPortfolioResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSellerPortfolio extends CreateRecord
{
    protected static string $resource = SellerPortfolioResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return CreateSellerPortfolioAction::execute($data);
    }
}
