<?php

namespace App\Classes\Orders\Validations;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Exception;

class OrderNotCanceledValidation extends OrderStatusValidation
{
    private string $message;

    public function __construct(string $message = 'No se puede modificar una orden cancelada')
    {
        $this->message = $message;
    }

    protected function check(Order $order, User $user, Carbon $date): void
    {
        if ($order->status === OrderStatus::CANCELED->value) {
            throw new Exception($this->message);
        }
    }
}
