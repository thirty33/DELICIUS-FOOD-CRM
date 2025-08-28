<?php

namespace App\Filament\Resources\DispatchRuleResource\Pages;

use App\Filament\Resources\DispatchRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDispatchRule extends EditRecord
{
    protected static string $resource = DispatchRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
