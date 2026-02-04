<?php

namespace App\Channels\Messages\WhatsApp\Types;

final readonly class Location
{
    public function __construct(
        private float $latitude,
        private float $longitude,
        private string $name,
        private string $address,
    )
    {}

    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'name' => $this->name,
            'address' => $this->address,
        ];
    }
}