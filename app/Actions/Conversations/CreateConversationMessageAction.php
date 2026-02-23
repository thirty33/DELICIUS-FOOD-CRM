<?php

namespace App\Actions\Conversations;

use App\Actions\Contracts\CreateAction;
use App\Models\Conversation;
use App\Models\Message;

final class CreateConversationMessageAction implements CreateAction
{
    public static function execute(array $data = []): Message
    {
        $conversation = Conversation::findOrFail(data_get($data, 'conversation_id'));
        $direction = data_get($data, 'direction');

        return $conversation->messages()->create([
            'direction' => $direction,
            'type' => data_get($data, 'type', 'text'),
            'body' => data_get($data, 'body'),
            'media_url' => data_get($data, 'media_url'),
            'metadata' => data_get($data, 'metadata'),
            'status' => $direction === 'inbound' ? 'received' : 'sent',
        ]);
    }
}
