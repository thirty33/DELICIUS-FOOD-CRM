<?php

namespace App\Repositories;

use App\Models\ReminderNotifiedMenu;
use App\Models\ReminderPendingNotification;
use Illuminate\Support\Collection;

class ReminderNotificationRepository
{
    public function getNotifiedMenuIds(int $triggerId, string $phoneNumber): Collection
    {
        return ReminderNotifiedMenu::query()
            ->where('trigger_id', $triggerId)
            ->where('phone_number', $phoneNumber)
            ->whereIn('status', ['sent', 'pending'])
            ->pluck('menu_id');
    }

    public function getPendingForConversation(int $conversationId): Collection
    {
        return ReminderPendingNotification::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'waiting_response')
            ->with('trigger.campaign')
            ->get();
    }

    public function getAllWaitingResponse(): Collection
    {
        return ReminderPendingNotification::query()
            ->where('status', 'waiting_response')
            ->get();
    }
}
