<?php

namespace App\Filament\Resources\OrderRuleResource\Pages;

use App\Filament\Resources\OrderRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrderRule extends EditRecord
{
    protected static string $resource = OrderRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
