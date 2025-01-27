<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use App\Models\Menu;
use App\Classes\UserPermissions;
use App\Enums\Weekday;
use Exception;
use Illuminate\Support\Facades\Log;

class DispatchRulesCategoriesValidation extends OrderStatusValidation
{

    private function getDayOfWeekInLowercase(string $date): string
    {
        // Parse the date using Carbon
        $carbonDate = Carbon::parse($date);

        // Get the day of the week in English and convert it to lowercase
        return strtolower($carbonDate->englishDayOfWeek);
    }

    protected function check(Order $order, User $user, Carbon $date): void
    {

        if (!$user->allow_late_orders) {

            $dayOfWeek = $this->getDayOfWeekInLowercase($date->toDateString());

            // Convertir el día de la semana a un valor del enum Weekday
            $weekdayEnum = Weekday::from($dayOfWeek);

            $dayOfWeekInSpanish = $weekdayEnum->toSpanish();

            // Loop through each OrderLine in the Order
            foreach ($order->orderLines as $orderLine) {

                // Get the product associated with the OrderLine
                $product = $orderLine->product;

                // Get the category of the product
                $category = $product->category;

                // Find a CategoryLine that matches the day of the week
                $categoryLine = $category->categoryLines
                    ->where('weekday', $dayOfWeek)
                    ->where('active', 1)
                    ->first();

                // If there is no CategoryLine for this day, continue with the next product
                if (!$categoryLine) {
                    throw new Exception("El producto '{$product->name}' no está disponible para el día {$dayOfWeekInSpanish}.");
                    // continue;
                }

                // Get the current date and time
                $todayWithHour = Carbon::now();
                $today = Carbon::now()->startOfDay();

                // Calculate the difference in days between the dispatch date and today
                $daysDifference = $date->diffInDays($today, true);

                // Get the preparation days from the CategoryLine
                $preparationDays = $categoryLine->preparation_days;

                // Validate based on the difference in days
                if ($daysDifference > $preparationDays) {
                    // No problem, the difference is greater than the preparation days
                    continue;
                } elseif ($daysDifference == $preparationDays) {
                    // The difference is equal to the preparation days, validate the time
                    $maximumOrderTime = Carbon::parse($categoryLine->maximum_order_time);

                    if ($todayWithHour->greaterThan($maximumOrderTime)) {
                        throw new Exception("El producto '{$product->name}' no puede ser pedido después de las {$maximumOrderTime->format('H:i')}.");
                    }
                } else {
                    // The difference is less than the preparation days, there is an error
                    throw new Exception("El producto '{$product->name}' no puede ser pedido para este día. Debe ser pedido con {$preparationDays} días de anticipación.");
                }
            }
        }
    }
}
