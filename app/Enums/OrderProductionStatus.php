<?php

namespace App\Enums;

enum OrderProductionStatus: string
{
    case FULLY_PRODUCED = 'completamente_producido';
    case PARTIALLY_PRODUCED = 'parcialmente_producido';
    case NOT_PRODUCED = 'no_producido';

    public function label(): string
    {
        return match ($this) {
            self::FULLY_PRODUCED => 'Completamente Producido',
            self::PARTIALLY_PRODUCED => 'Parcialmente Producido',
            self::NOT_PRODUCED => 'No Producido',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FULLY_PRODUCED => 'success',
            self::PARTIALLY_PRODUCED => 'warning',
            self::NOT_PRODUCED => 'danger',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::FULLY_PRODUCED => 'fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-1.5 min-w-[theme(spacing.6)] py-1 fi-color-success bg-success-50 text-success-600 ring-success-600 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400',
            self::PARTIALLY_PRODUCED => 'fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-1.5 min-w-[theme(spacing.6)] py-1 fi-color-warning bg-warning-50 text-warning-600 ring-warning-600 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400',
            self::NOT_PRODUCED => 'fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-1.5 min-w-[theme(spacing.6)] py-1 fi-color-danger bg-danger-50 text-danger-600 ring-danger-600 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400',
        };
    }

    public static function getSelectOptions(): array
    {
        return [
            self::FULLY_PRODUCED->value => self::FULLY_PRODUCED->label(),
            self::PARTIALLY_PRODUCED->value => self::PARTIALLY_PRODUCED->label(),
            self::NOT_PRODUCED->value => self::NOT_PRODUCED->label(),
        ];
    }
}
