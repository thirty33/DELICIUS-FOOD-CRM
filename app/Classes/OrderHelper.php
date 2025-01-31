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
     * Valida si un producto puede ser pedido en función de los días de preparación y la hora máxima de pedido.
     *
     * @param Product $product
     * @param Carbon $date
     * @param mixed $categoryLine
     * @throws Exception
     */
    public static function validatePreparationTime(Product $product, Carbon $date, $categoryLine): void
    {
        $todayWithHour = Carbon::now();
        $today = Carbon::now()->startOfDay();
        $daysDifference = $date->diffInDays($today, true);
        $preparationDays = $categoryLine->preparation_days;

        if ($daysDifference > $preparationDays) {
            return;
        } elseif ($daysDifference == $preparationDays) {
            $maximumOrderTime = Carbon::parse($categoryLine->maximum_order_time);
            if ($todayWithHour->greaterThan($maximumOrderTime)) {
                throw new Exception("El producto '{$product->name}' no puede ser pedido después de las {$maximumOrderTime->format('H:i')}.");
            }
        } else {
            throw new Exception("El producto '{$product->name}' no puede ser pedido para este día. Debe ser pedido con {$preparationDays} días de anticipación.");
        }
    }

    /**
     * Obtiene el día de la semana en español para una fecha dada.
     *
     * @param string $date
     * @return string
     */
    public static function getDayOfWeekInSpanish(string $date): string
    {
        $dayOfWeek = self::getDayOfWeekInLowercase($date);
        $weekdayEnum = Weekday::from($dayOfWeek);
        return $weekdayEnum->toSpanish();
    }

    /**
     * Valida si un producto está disponible para un día específico.
     *
     * @param Product $product
     * @param mixed $category
     * @param string $dayOfWeek
     * @param string $dayOfWeekInSpanish
     * @throws Exception
     */
    public static function validateProductAvailability(Product $product, $category, string $dayOfWeek, string $dayOfWeekInSpanish, User $user = null): void
    {
        $categoryLine = self::getCategoryLineForDay($category, $dayOfWeek, $user);

        if (!$categoryLine) {
            throw new Exception("El producto '{$product->name}' no está disponible para el día {$dayOfWeekInSpanish}.");
        }
    }

    /**
     * Obtiene la CategoryLine para un día específico.
     *
     * @param mixed $category
     * @param string $dayOfWeek
     * @return mixed
     */
    public static function getCategoryLineForDay($category, string $dayOfWeek, User $user = null)
    {
        // Si se proporciona un usuario, buscar primero en sus categoryUserLines
        if ($user) {

            $userCategoryLine = $user->categoryUserLines
                ->where('category_id', $category->id)
                ->where('weekday', $dayOfWeek)
                ->where('active', 1)
                ->first();
            
            if ($userCategoryLine) {
                return $userCategoryLine;
            }
            
        }

        // Si no se encuentra en las categoryUserLines del usuario, buscar en las categoryLines de la categoría
        return $category->categoryLines
            ->where('weekday', $dayOfWeek)
            ->where('active', 1)
            ->first();
    }

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
        if ($user->allow_late_orders) {
            $dayOfWeek = self::getDayOfWeekInLowercase($date->toDateString());
            $weekdayEnum = Weekday::from($dayOfWeek);
            $dayOfWeekInSpanish = $weekdayEnum->toSpanish();

            $category = $product->category;

            $categoryLine = self::getCategoryLineForDay($category, $dayOfWeek, $user);

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
