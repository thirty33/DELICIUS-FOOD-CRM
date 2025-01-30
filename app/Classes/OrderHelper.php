<?php

namespace App\Classes;

use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use App\Enums\Weekday;
use Exception;

class OrderHelper
{
    /**
     * Valida las reglas de CategoryLine para un producto específico.
     *
     * @param Product $product
     * @param Carbon $date
     * @param User $user
     * @throws Exception
     */
    public static function validateCategoryLineRulesForProduct(Product $product, Carbon $date, User $user): void
    {
        if (!$user->allow_late_orders) {
            $dayOfWeek = self::getDayOfWeekInLowercase($date->toDateString());
            $weekdayEnum = Weekday::from($dayOfWeek);
            $dayOfWeekInSpanish = $weekdayEnum->toSpanish();

            $category = $product->category;

            $categoryLine = $category->categoryLines
                ->where('weekday', $dayOfWeek)
                ->where('active', 1)
                ->first();

            if (!$categoryLine) {
                throw new Exception("El producto '{$product->name}' no está disponible para el día {$dayOfWeekInSpanish}.");
            }

            $todayWithHour = Carbon::now();
            $today = Carbon::now()->startOfDay();
            $daysDifference = $date->diffInDays($today, true);
            $preparationDays = $categoryLine->preparation_days;

            if ($daysDifference > $preparationDays) {
                return;
            } elseif ($daysDifference == $preparationDays) {
                $maximumOrderTime = Carbon::parse($categoryLine->maximum_order_time);

                if ($todayWithHour->greaterThan($maximumOrderTime)) {
                    throw new Exception("El producto '{$product->name}' no puede ser modificado. El tiempo de preparación del producto ya ha comenzado.");
                }
            } else {
                throw new Exception("El producto '{$product->name}' no puede ser modificado. El tiempo de preparación del producto ya ha comenzado.");
            }
        }
    }

    /**
     * Obtiene el día de la semana en minúsculas para una fecha dada.
     *
     * @param string $date
     * @return string
     */
    public static function getDayOfWeekInLowercase(string $date): string
    {
        $carbonDate = Carbon::parse($date);
        return strtolower($carbonDate->englishDayOfWeek);
    }
}
