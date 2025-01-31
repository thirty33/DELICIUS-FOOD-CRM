<?php

namespace App\Classes\Orders\Validations;

use App\Classes\OrderHelper;
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
    protected function check(Order $order, User $user, Carbon $date): void
    {
        if ($user->allow_late_orders) {

            $dayOfWeek = OrderHelper::getDayOfWeekInLowercase($date->toDateString());
            $dayOfWeekInSpanish = OrderHelper::getDayOfWeekInSpanish($date->toDateString());
    
            foreach ($order->orderLines as $orderLine) {

                if ($orderLine->partially_scheduled) {
                    continue;
                }
                
                $product = $orderLine->product;
                $category = $product->category;
    
                // Validar disponibilidad del producto
                OrderHelper::validateProductAvailability($product, $category, $dayOfWeek, $dayOfWeekInSpanish, $user);
    
                // Obtener la CategoryLine
                $categoryLine = OrderHelper::getCategoryLineForDay($category, $dayOfWeek, $user);
    
                // Validar el tiempo de preparación
                OrderHelper::validatePreparationTime($product, $date, $categoryLine);


            }
        }
    }
}
