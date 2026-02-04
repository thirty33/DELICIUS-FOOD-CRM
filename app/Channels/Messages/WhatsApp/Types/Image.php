<?php

namespace App\Channels\Messages\WhatsApp\Types;

final readonly class Image
{
    public function __construct(
        private string $link,
        private string $caption,
    )
    {}

    public function toArray(): array
    {
        return [
            'link' => $this->link,
            'caption' => $this->caption,
        ];
    }
}