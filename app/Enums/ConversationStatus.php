<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ConversationStatus: string implements HasLabel, HasColor, HasIcon
{
    case NEW_CONVERSATION = 'new_conversation';
    case RECEIVED = 'received';
    case AWAITING_REPLY = 'awaiting_reply';
    case CLOSED = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::NEW_CONVERSATION => __('Nueva conversación'),
            self::RECEIVED => __('Mensaje recibido'),
            self::AWAITING_REPLY => __('Esperando respuesta'),
            self::CLOSED => __('Cerrada'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NEW_CONVERSATION => 'info',
            self::RECEIVED => 'warning',
            self::AWAITING_REPLY => 'success',
            self::CLOSED => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::NEW_CONVERSATION => 'heroicon-o-plus-circle',
            self::RECEIVED => 'heroicon-o-bell-alert',
            self::AWAITING_REPLY => 'heroicon-o-clock',
            self::CLOSED => 'heroicon-o-check-circle',
        };
    }
}