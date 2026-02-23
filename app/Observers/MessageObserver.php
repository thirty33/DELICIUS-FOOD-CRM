<?php

namespace App\Observers;

use App\Jobs\NotifyAdminsOfIncomingMessage;
use App\Models\Message;
use App\Notifications\WhatsApp\TextNotification;

class MessageObserver
{
    public function created(Message $message): void
    {
        if ($message->direction === 'inbound') {
            NotifyAdminsOfIncomingMessage::dispatch($message);

            return;
        }

        if ($message->direction === 'outbound' && $message->type === 'text') {
            $conversation = $message->conversation;
            $notifiable = $conversation->company ?? $conversation->branch;

            if (! $notifiable) {
                return;
            }

            $notifiable->notify(new TextNotification(false, $message->body, $message->id));
        }
    }
}
