<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class E164PhoneNumber implements CastsAttributes
{
    private const COUNTRY_CODES = [
        '56', // Chile
        '57', // Colombia
        '58', // Venezuela
        '54', // Argentina
        '55', // Brazil
        '51', // Peru
        '52', // Mexico
        '53', // Cuba
        '591', // Bolivia
        '593', // Ecuador
        '595', // Paraguay
        '598', // Uruguay
        '1',  // USA/Canada
        '34', // Spain
    ];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (empty($value) || $value === 'SIN INFORMACION') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        if (empty($digits)) {
            return null;
        }

        if ($this->hasCountryCode($digits)) {
            return $digits;
        }

        return config('whatsapp.default_country_code') . $digits;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    private function hasCountryCode(string $digits): bool
    {
        // Sort by length descending so 3-digit codes match before 2-digit ones
        $sorted = collect(self::COUNTRY_CODES)->sortByDesc(fn ($c) => strlen($c));

        foreach ($sorted as $code) {
            if (str_starts_with($digits, $code) && strlen($digits) > strlen($code) + 6) {
                return true;
            }
        }

        return false;
    }
}