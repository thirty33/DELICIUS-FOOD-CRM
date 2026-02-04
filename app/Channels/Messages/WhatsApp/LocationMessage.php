<?php

namespace App\Channels\Messages\WhatsApp;

use App\Channels\Messages\WhatsApp\Types\Location;

final class LocationMessage extends WhatsAppMessage
{
    public function __construct(private readonly Location $location)
    {}

    public function toArray(): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'type' => 'location',
            'location' => $this->location->toArray(),
        ];
    }
}