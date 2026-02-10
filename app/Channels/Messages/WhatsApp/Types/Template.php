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
        $data = [
            'name' => $this->templateName,
            'language' => [
                'code' => $this->languageCode,
            ],
        ];

        if (! empty($this->components)) {
            $data['components'] = $this->components;
        }

        return $data;
    }
}