<?php

namespace App\Filament\Resources\ProductionAreaResource\Pages;

use App\Filament\Resources\ProductionAreaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductionAreas extends ListRecords
{
    protected static string $resource = ProductionAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
