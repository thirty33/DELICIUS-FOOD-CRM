<?php

namespace App\Filament\Resources\WarehouseTransactionResource\Pages;

use App\Filament\Resources\WarehouseTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWarehouseTransactions extends ListRecords
{
    protected static string $resource = WarehouseTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
