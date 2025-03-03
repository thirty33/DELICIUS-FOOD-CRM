<?php

namespace App\Filament\Resources\ImportProcessResource\Pages;

use App\Filament\Resources\ImportProcessResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditImportProcess extends EditRecord
{
    protected static string $resource = ImportProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
