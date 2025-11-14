<?php

namespace App\Filament\Resources\ReportConfigurationResource\Pages;

use App\Filament\Resources\ReportConfigurationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReportConfiguration extends EditRecord
{
    protected static string $resource = ReportConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
