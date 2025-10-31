<?php

namespace App\Enums;

enum AdvanceOrderStatus: string
{
    case PENDING = 'pending';
    case CANCELLED = 'cancelled';
    case EXECUTED = 'executed';

    public function label(): string
    {
        return match($this) {
            self::PENDING => __('Pendiente'),
            self::CANCELLED => __('Cancelado'),
            self::EXECUTED => __('Ejecutado'),
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PENDING => 'warning',
            self::CANCELLED => 'danger',
            self::EXECUTED => 'success',
        };
    }
}
