<?php

namespace App\Services\Chat;

use App\Jobs\ProcessIncomingMessage;

class IncomingMessageService
{
    public function handle(int $conversationId, string $body, string $type = 'text'): void
    {
        ProcessIncomingMessage::dispatch($conversationId, $body, $type);
    }
}