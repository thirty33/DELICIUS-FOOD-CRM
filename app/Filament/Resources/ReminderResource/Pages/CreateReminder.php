<?php

namespace App\Filament\Resources\ReminderResource\Pages;

use App\Actions\Campaigns\CreateReminderAction;
use App\Filament\Resources\ReminderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReminder extends CreateRecord
{
    protected static string $resource = ReminderResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return CreateReminderAction::execute($data);
    }
}