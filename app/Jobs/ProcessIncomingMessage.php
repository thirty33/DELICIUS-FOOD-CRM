<?php

namespace App\Jobs;

use App\Actions\Conversations\CreateConversationMessageAction;
use App\Actions\Conversations\UpdateConversationStatusAction;
use App\Enums\ConversationStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $conversationId,
        public string $body,
        public string $type = 'text',
    ) {}

    public function handle(): void
    {
        CreateConversationMessageAction::execute([
            'conversation_id' => $this->conversationId,
            'direction' => 'inbound',
            'type' => $this->type,
            'body' => $this->body,
        ]);

        UpdateConversationStatusAction::execute([
            'conversation_id' => $this->conversationId,
            'status' => ConversationStatus::RECEIVED,
        ]);
    }
}