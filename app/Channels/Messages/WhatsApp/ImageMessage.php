<?php

namespace App\Channels\Messages\WhatsApp;

use App\Channels\Messages\WhatsApp\Types\Image;

final class ImageMessage extends WhatsAppMessage
{
    public function __construct(private readonly Image $image)
    {}

    public function toArray(): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'type' => 'image',
            'image' => $this->image->toArray(),
        ];
    }
}