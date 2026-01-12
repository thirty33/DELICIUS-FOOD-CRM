<?php

namespace App\Filament\Resources\WebRegistrationRequestResource\Pages;

use App\Filament\Resources\WebRegistrationRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWebRegistrationRequests extends ListRecords
{
    protected static string $resource = WebRegistrationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
