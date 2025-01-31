<?php

namespace App\Classes\Orders\Validations;

use App\Classes\PriceFormatter;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Exception;

class MaxOrderAmountValidation extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {   
        if(!$user->validate_min_price) {
            return;
        }

        $formatterPrice = PriceFormatter::format($user->branch->min_price_order);
        if($user->branch->min_price_order > $order->total) {
            throw new Exception("El monto del pedido mínimo es {$formatterPrice}");
        }
    }
} 