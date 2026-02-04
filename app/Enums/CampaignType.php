<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CampaignType: string implements HasLabel, HasColor
{
    case CAMPAIGN = 'campaign';
    case REMINDER = 'reminder';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::CAMPAIGN => 'Campaña',
            self::REMINDER => 'Recordatorio',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::CAMPAIGN => 'info',
            self::REMINDER => 'warning',
        };
    }
}