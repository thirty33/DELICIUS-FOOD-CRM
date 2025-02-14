<?php

namespace App\Filament\Resources\ImportProcessResource\Pages;

use App\Filament\Resources\ImportProcessResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListImportProcesses extends ListRecords
{
    protected static string $resource = ImportProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }
}
