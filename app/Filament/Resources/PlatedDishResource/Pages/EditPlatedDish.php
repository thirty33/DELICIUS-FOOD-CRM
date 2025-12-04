<?php

namespace App\Filament\Resources\PlatedDishResource\Pages;

use App\Filament\Resources\PlatedDishResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlatedDish extends EditRecord
{
    protected static string $resource = PlatedDishResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
