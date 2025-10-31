<?php

namespace App\Filament\Resources\AdvanceOrderResource\Pages;

use App\Filament\Resources\AdvanceOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdvanceOrders extends ListRecords
{
    protected static string $resource = AdvanceOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
