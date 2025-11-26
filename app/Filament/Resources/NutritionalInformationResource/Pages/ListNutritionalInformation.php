<?php

namespace App\Filament\Resources\NutritionalInformationResource\Pages;

use App\Filament\Resources\NutritionalInformationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNutritionalInformation extends ListRecords
{
    protected static string $resource = NutritionalInformationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
