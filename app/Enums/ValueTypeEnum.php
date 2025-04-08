<?php

namespace App\Enums;

enum ValueTypeEnum: string
{
    case TEXT = 'text';
    case NUMERIC = 'numeric';
    case INTEGER = 'integer';
    case BOOLEAN = 'boolean';
    case JSON = 'json';

    public function getLabel(): string
    {
        return match($this) {
            self::TEXT => 'Texto',
            self::NUMERIC => 'Numérico',
            self::INTEGER => 'Entero',
            self::BOOLEAN => 'Booleano',
            self::JSON => 'JSON',
        };
    }

    public static function options(): array
    {
        return [
            self::TEXT->value => self::TEXT->getLabel(),
            self::NUMERIC->value => self::NUMERIC->getLabel(),
            self::INTEGER->value => self::INTEGER->getLabel(),
            self::BOOLEAN->value => self::BOOLEAN->getLabel(),
            self::JSON->value => self::JSON->getLabel(),
        ];
    }
}