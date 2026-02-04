<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CampaignMessageStatus: string implements HasLabel, HasColor
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::SENT => 'Enviado',
            self::DELIVERED => 'Entregado',
            self::FAILED => 'Fallido',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::SENT => 'info',
            self::DELIVERED => 'success',
            self::FAILED => 'danger',
        };
    }
}