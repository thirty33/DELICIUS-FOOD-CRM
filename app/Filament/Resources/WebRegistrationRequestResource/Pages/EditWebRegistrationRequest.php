<?php

namespace App\Filament\Resources\WebRegistrationRequestResource\Pages;

use App\Filament\Resources\WebRegistrationRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWebRegistrationRequest extends EditRecord
{
    protected static string $resource = WebRegistrationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
