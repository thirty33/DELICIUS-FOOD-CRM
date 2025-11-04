<?php

namespace App\Filament\Resources\ProductionAreaResource\Pages;

use App\Filament\Resources\ProductionAreaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductionArea extends EditRecord
{
    protected static string $resource = ProductionAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
