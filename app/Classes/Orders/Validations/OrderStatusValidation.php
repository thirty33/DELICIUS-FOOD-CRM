<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;

abstract class OrderStatusValidation
{
    private $next;
    protected ?User $userForValidations = null;

    public function linkWith(OrderStatusValidation $next): OrderStatusValidation
    {
        $this->next = $next;
        return $next;
    }

    public function setUserForValidations(?User $user): self
    {
        $this->userForValidations = $user;
        return $this;
    }

    public function validate(Order $order, User $user, Carbon $date): void
    {
        // Skip all validations if userForValidations is super_master_user
        if ($this->userForValidations && $this->userForValidations->super_master_user) {
            return;
        }

        $this->check($order, $user, $date);

        if ($this->next) {
            // Propagate userForValidations to next validation in chain
            if ($this->userForValidations) {
                $this->next->setUserForValidations($this->userForValidations);
            }
            $this->next->validate($order, $user, $date);
        }
    }

    abstract protected function check(Order $order, User $user, Carbon $date): void;
}