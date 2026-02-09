<?php

namespace App\Actions\Reminders;

use App\Actions\Contracts\CreateAction;
use App\Models\ReminderPendingNotification;

final class CreateReminderPendingNotificationAction implements CreateAction
{
    public static function execute(array $data = []): ReminderPendingNotification
    {
        $triggerId = data_get($data, 'trigger_id');
        $conversationId = data_get($data, 'conversation_id');
        $menuId = data_get($data, 'menu_id');

        $pending = ReminderPendingNotification::where('trigger_id', $triggerId)
            ->where('conversation_id', $conversationId)
            ->where('status', 'waiting_response')
            ->first();

        if ($pending) {
            $menuIds = $pending->menu_ids;
            if (! in_array($menuId, $menuIds)) {
                $menuIds[] = $menuId;
                $pending->update(['menu_ids' => $menuIds]);
            }

            return $pending;
        }

        return ReminderPendingNotification::create([
            'trigger_id' => $triggerId,
            'conversation_id' => $conversationId,
            'phone_number' => data_get($data, 'phone_number'),
            'message_content' => data_get($data, 'message_content'),
            'menu_ids' => [$menuId],
            'status' => 'waiting_response',
        ]);
    }
}