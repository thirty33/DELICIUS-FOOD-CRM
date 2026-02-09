<?php

namespace App\Actions\Reminders;

use App\Actions\Conversations\CreateConversationMessageAction;
use App\Models\ReminderNotifiedMenu;
use App\Models\ReminderPendingNotification;

final class SendPendingReminderAction
{
    public static function execute(array $data = []): ReminderPendingNotification
    {
        $pending = ReminderPendingNotification::findOrFail(data_get($data, 'pending_id'));

        CreateConversationMessageAction::execute([
            'conversation_id' => $pending->conversation_id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $pending->message_content,
        ]);

        $pending->update(['status' => 'sent']);

        ReminderNotifiedMenu::query()
            ->where('trigger_id', $pending->trigger_id)
            ->where('phone_number', $pending->phone_number)
            ->where('status', 'pending')
            ->update([
                'status' => 'sent',
                'notified_at' => now(),
            ]);

        return $pending;
    }
}
