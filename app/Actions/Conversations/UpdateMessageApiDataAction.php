<?php

namespace App\Actions\Conversations;

use App\Actions\Contracts\UpdateAction;
use App\Models\Message;

final class UpdateMessageApiDataAction implements UpdateAction
{
    public static function execute(array $data = []): Message
    {
        $message = Message::findOrFail(data_get($data, 'message_id'));

        $message->update([
            'api_request' => data_get($data, 'api_request'),
            'api_response' => data_get($data, 'api_response'),
        ]);

        return $message;
    }
}
