<?php

namespace App\Enums;

/**
 * Enum to determine the appropriate font size for ingredients text
 * based on the character length of the ingredients string.
 *
 * This ensures ingredients text fits within the label without overflow.
 */
enum IngredientsFontSize: string
{
    case EXTRA_LARGE = '6.5';    // 0-100 chars
    case LARGE = '6';            // 101-150 chars
    case MEDIUM = '5.5';         // 151-250 chars
    case SMALL = '5';            // 251-350 chars
    case EXTRA_SMALL = '4.5';    // 351-500 chars
    case TINY = '4';             // 501+ chars

    /**
     * Get the appropriate font size based on ingredients text length.
     */
    public static function fromLength(int $length): self
    {
        return match (true) {
            $length <= 100 => self::EXTRA_LARGE,
            $length <= 150 => self::LARGE,
            $length <= 250 => self::MEDIUM,
            $length <= 350 => self::SMALL,
            $length <= 500 => self::EXTRA_SMALL,
            default => self::TINY,
        };
    }

    /**
     * Get the font size value in pixels.
     */
    public function pixels(): string
    {
        return $this->value . 'px';
    }
}