<?php

namespace App\Filament\Resources\WarehouseTransactionResource\Pages;

use App\Filament\Resources\WarehouseTransactionResource;
use Filament\Resources\Pages\EditRecord;

class EditWarehouseTransaction extends EditRecord
{
    protected static string $resource = WarehouseTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            WarehouseTransactionResource::getHeaderExecuteAction(),
            WarehouseTransactionResource::getHeaderCancelAction(),
        ];
    }
}
