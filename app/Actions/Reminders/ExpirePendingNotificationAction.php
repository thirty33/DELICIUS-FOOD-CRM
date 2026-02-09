<?php

namespace App\Actions\Reminders;

use App\Models\ReminderNotifiedMenu;
use App\Models\ReminderPendingNotification;

final class ExpirePendingNotificationAction
{
    public static function execute(array $data = []): ReminderPendingNotification
    {
        $pending = ReminderPendingNotification::findOrFail(data_get($data, 'pending_id'));

        $pending->update(['status' => 'expired']);

        ReminderNotifiedMenu::query()
            ->where('trigger_id', $pending->trigger_id)
            ->where('phone_number', $pending->phone_number)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);

        return $pending;
    }
}