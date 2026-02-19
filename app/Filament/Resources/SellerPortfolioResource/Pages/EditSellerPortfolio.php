<?php

namespace App\Filament\Resources\SellerPortfolioResource\Pages;

use App\Actions\Sellers\UpdateSellerPortfolioAction;
use App\Filament\Resources\SellerPortfolioResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSellerPortfolio extends EditRecord
{
    protected static string $resource = SellerPortfolioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data['id'] = $record->id;

        return UpdateSellerPortfolioAction::execute($data);
    }
}
