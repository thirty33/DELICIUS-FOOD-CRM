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
        \Illuminate\Support\Facades\Log::info('MaxOrderAmountValidation: check started', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_validate_min_price' => $user->validate_min_price,
        ]);

        if(!$user->validate_min_price) {
            \Illuminate\Support\Facades\Log::info('MaxOrderAmountValidation: validation skipped (validate_min_price = false)');
            return;
        }

        $formatterPrice = PriceFormatter::format($user->branch->min_price_order);

        \Illuminate\Support\Facades\Log::info('MaxOrderAmountValidation: comparing amounts', [
            'order_id' => $order->id,
            'branch_min_price_order' => $user->branch->min_price_order,
            'order_total' => $order->total,
            'will_fail' => $user->branch->min_price_order > $order->total,
            'formatted_min_price' => $formatterPrice,
        ]);

        if($user->branch->min_price_order > $order->total) {
            \Illuminate\Support\Facades\Log::warning('MaxOrderAmountValidation: VALIDATION FAILED', [
                'order_id' => $order->id,
                'min_required' => $user->branch->min_price_order,
                'order_total' => $order->total,
                'difference' => $user->branch->min_price_order - $order->total,
            ]);
            throw new Exception("El monto del pedido mínimo es {$formatterPrice}");
        }

        \Illuminate\Support\Facades\Log::info('MaxOrderAmountValidation: validation passed');
    }
} 