<?php

namespace App\Classes\Orders\Validations;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Exception;

class OrderNotProcessedValidation extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {
        if ($order->status === OrderStatus::PROCESSED->value) {
            throw new Exception('La orden ya ha sido procesada');
        }
    }
}
