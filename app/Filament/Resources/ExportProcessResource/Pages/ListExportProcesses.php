<?php

namespace App\Filament\Resources\ExportProcessResource\Pages;

use App\Filament\Resources\ExportProcessResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExportProcesses extends ListRecords
{
    protected static string $resource = ExportProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }
}
