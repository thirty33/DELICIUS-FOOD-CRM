<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CampaignChannel: string implements HasLabel, HasColor
{
    case WHATSAPP = 'whatsapp';
    case EMAIL = 'email';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::WHATSAPP => 'WhatsApp',
            self::EMAIL => 'Correo electrónico',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::WHATSAPP => 'success',
            self::EMAIL => 'info',
        };
    }
}