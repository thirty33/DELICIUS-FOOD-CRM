<?php

namespace App\Services\Reminders;

use App\Actions\Reminders\ExpirePendingNotificationAction;
use App\Actions\Reminders\SendPendingReminderAction;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\ReminderPendingNotification;
use App\Repositories\ReminderNotificationRepository;

class CheckPendingRemindersService
{
    public function __construct(
        private ReminderNotificationRepository $notificationRepository,
    ) {}

    /**
     * @return array{total_checked: int, sent: int, expired: int, unchanged: int}
     */
    public function checkAll(): array
    {
        $pendings = $this->notificationRepository->getAllWaitingResponse();

        $stats = ['total_checked' => 0, 'sent' => 0, 'expired' => 0, 'unchanged' => 0];

        foreach ($pendings as $pending) {
            $result = $this->processPending($pending);
            $stats['total_checked']++;
            $stats[$result]++;
        }

        return $stats;
    }

    private function processPending(ReminderPendingNotification $pending): string
    {
        $conversation = Conversation::find($pending->conversation_id);

        if (! $conversation) {
            ExpirePendingNotificationAction::execute(['pending_id' => $pending->id]);

            return 'expired';
        }

        if ($conversation->status === ConversationStatus::CLOSED) {
            ExpirePendingNotificationAction::execute(['pending_id' => $pending->id]);

            return 'expired';
        }

        $hasInbound = $conversation->messages()
            ->where('direction', 'inbound')
            ->exists();

        if ($hasInbound) {
            SendPendingReminderAction::execute(['pending_id' => $pending->id]);

            return 'sent';
        }

        $expirationHours = config('reminders.pending_expiration_hours', 48);
        $isExpired = $pending->created_at->addHours($expirationHours)->lte(now());

        if ($isExpired) {
            ExpirePendingNotificationAction::execute(['pending_id' => $pending->id]);

            return 'expired';
        }

        return 'unchanged';
    }
}