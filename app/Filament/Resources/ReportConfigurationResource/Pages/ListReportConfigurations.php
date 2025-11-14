<?php

namespace App\Filament\Resources\ReportConfigurationResource\Pages;

use App\Filament\Resources\ReportConfigurationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReportConfigurations extends ListRecords
{
    protected static string $resource = ReportConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
