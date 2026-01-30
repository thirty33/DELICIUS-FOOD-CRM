<?php

namespace App\Observers;

use App\Jobs\NotifyAdminsOfIncomingMessage;
use App\Models\Message;

class MessageObserver
{
    public function created(Message $message): void
    {
        if ($message->direction !== 'inbound') {
            return;
        }

        NotifyAdminsOfIncomingMessage::dispatch($message);
    }
}