<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TriggerType: string implements HasLabel, HasColor
{
    case EVENT = 'event';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::EVENT => 'Evento',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::EVENT => 'success',
        };
    }
}