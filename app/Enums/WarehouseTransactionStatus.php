<?php

namespace App\Enums;

enum WarehouseTransactionStatus: string
{
    case PENDING = 'pending';
    case EXECUTED = 'executed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::PENDING => __('Pendiente'),
            self::EXECUTED => __('Ejecutada'),
            self::CANCELLED => __('Cancelada'),
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PENDING => 'warning',
            self::EXECUTED => 'success',
            self::CANCELLED => 'danger',
        };
    }
}
