<?php

namespace App\Filament\Resources\ExportProcessResource\Pages;

use App\Filament\Resources\ExportProcessResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditExportProcess extends EditRecord
{
    protected static string $resource = ExportProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
