<?php

namespace App\Filament\Resources\NutritionalInformationResource\Pages;

use App\Filament\Resources\NutritionalInformationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNutritionalInformation extends EditRecord
{
    protected static string $resource = NutritionalInformationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
