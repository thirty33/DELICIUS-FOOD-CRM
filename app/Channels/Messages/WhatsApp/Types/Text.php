<?php

namespace App\Channels\Messages\WhatsApp\Types;

final readonly class Text
{
    public function __construct(
        private bool $previewUrl,
        private string $body,
    )
    {}

    public function toArray(): array
    {
        return [
            'preview_url' => $this->previewUrl,
            'body' => $this->body,
        ];
    }
}