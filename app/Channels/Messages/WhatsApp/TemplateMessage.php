<?php

namespace App\Channels\Messages\WhatsApp;

use App\Channels\Messages\WhatsApp\Types\Template;

final class TemplateMessage extends WhatsAppMessage
{
    public function __construct(private readonly Template $template)
    {}

    public function toArray(): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'type' => 'template',
            'template' => $this->template->toArray(),
        ];
    }
}