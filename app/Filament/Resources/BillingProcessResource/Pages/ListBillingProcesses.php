<?php

namespace App\Filament\Resources\BillingProcessResource\Pages;

use App\Filament\Resources\BillingProcessResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBillingProcesses extends ListRecords
{
    protected static string $resource = BillingProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
