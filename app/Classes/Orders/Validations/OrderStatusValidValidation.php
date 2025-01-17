<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Exception;

class OrderStatusValidValidation extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {
        // if (!in_array($order->status, ['pending', 'approved', 'rejected'])) {
        //     throw new Exception("Invalid order status.");
        // }
    }
}