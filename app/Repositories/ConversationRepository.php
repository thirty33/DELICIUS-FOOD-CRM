<?php

namespace App\Repositories;

use App\Enums\ConversationStatus;
use App\Models\Conversation;

class ConversationRepository
{
    public function findActiveByPhoneNumber(string $phoneNumber): ?Conversation
    {
        return Conversation::query()
            ->where('phone_number', $phoneNumber)
            ->where('status', '!=', ConversationStatus::CLOSED->value)
            ->first();
    }

    public function hasUserResponded(Conversation $conversation): bool
    {
        $hasInbound = $conversation->messages()
            ->where('direction', 'inbound')
            ->exists();

        $hasOutbound = $conversation->messages()
            ->where('direction', 'outbound')
            ->exists();

        return $hasInbound && $hasOutbound;
    }

    public function hasInboundMessages(Conversation $conversation): bool
    {
        return $conversation->messages()
            ->where('direction', 'inbound')
            ->exists();
    }
}
