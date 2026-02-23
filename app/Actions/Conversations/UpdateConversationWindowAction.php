<?php

namespace App\Actions\Conversations;

use App\Actions\Contracts\UpdateAction;
use App\Models\Conversation;

final class UpdateConversationWindowAction implements UpdateAction
{
    public static function execute(array $data = []): Conversation
    {
        $conversation = Conversation::findOrFail(data_get($data, 'conversation_id'));

        $conversation->update([
            'window_expires_at' => now()->addHours(24),
        ]);

        return $conversation;
    }
}
