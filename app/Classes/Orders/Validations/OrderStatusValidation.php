<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;

abstract class OrderStatusValidation
{
    private $next;

    public function linkWith(OrderStatusValidation $next): OrderStatusValidation
    {
        $this->next = $next;
        return $next;
    }

    public function validate(Order $order, User $user, Carbon $date): void
    {
        $this->check($order, $user, $date);

        if ($this->next) {
            $this->next->validate($order, $user, $date);
        }
    }

    abstract protected function check(Order $order, User $user, Carbon $date): void;
}