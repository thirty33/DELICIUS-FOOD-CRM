<?php

namespace App\Channels\Messages\WhatsApp;

use App\Channels\Messages\WhatsApp\Types\Text;

final class TextMessage extends WhatsAppMessage
{
    public function __construct(private readonly Text $text)
    {}

    public function toArray(): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'type' => 'text',
            'text' => $this->text->toArray(),
        ];
    }
}