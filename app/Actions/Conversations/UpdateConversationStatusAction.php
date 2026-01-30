<?php

namespace App\Actions\Conversations;

use App\Actions\Contracts\UpdateAction;
use App\Enums\ConversationStatus;
use App\Models\Conversation;

final class UpdateConversationStatusAction implements UpdateAction
{
    public static function execute(array $data = []): Conversation
    {
        $conversation = Conversation::findOrFail(data_get($data, 'conversation_id'));

        $conversation->update([
            'status' => data_get($data, 'status'),
            'last_message_at' => now(),
        ]);

        return $conversation;
    }
}