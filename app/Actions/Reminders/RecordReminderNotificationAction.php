<?php

namespace App\Actions\Reminders;

use App\Actions\Contracts\CreateAction;
use App\Models\ReminderNotifiedMenu;

final class RecordReminderNotificationAction implements CreateAction
{
    public static function execute(array $data = []): ReminderNotifiedMenu
    {
        return ReminderNotifiedMenu::create([
            'trigger_id' => data_get($data, 'trigger_id'),
            'menu_id' => data_get($data, 'menu_id'),
            'phone_number' => data_get($data, 'phone_number'),
            'conversation_id' => data_get($data, 'conversation_id'),
            'status' => data_get($data, 'status', 'pending'),
            'notified_at' => data_get($data, 'status') === 'sent' ? now() : null,
        ]);
    }
}