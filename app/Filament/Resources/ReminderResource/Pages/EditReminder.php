<?php

namespace App\Filament\Resources\ReminderResource\Pages;

use App\Actions\Campaigns\UpdateReminderAction;
use App\Filament\Resources\ReminderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReminder extends EditRecord
{
    protected static string $resource = ReminderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $trigger = $this->record->triggers()->first();

        if ($trigger) {
            $data['event_type'] = $trigger->event_type?->value;
            $data['hours_before'] = $trigger->hours_before;
            $data['hours_after'] = $trigger->hours_after;
        }

        return $data;
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $data['id'] = $record->id;

        return UpdateReminderAction::execute($data);
    }
}