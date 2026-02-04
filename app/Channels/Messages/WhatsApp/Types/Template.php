<?php

namespace App\Channels\Messages\WhatsApp\Types;

final readonly class Template
{
    public function __construct(
        private string $templateName,
        private string $languageCode = 'en_US',
        private array $components = [],
    )
    {}

    public function toArray(): array
    {
        return [
            'name' => $this->templateName,
            'language' => [
                'code' => $this->languageCode,
            ],
            'components' => $this->components,
        ];
    }
}