<?php

namespace App\Filters\Order;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class OrderStatusFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        if (!$this->filter->getValue()) {
            return $next($items);
        }

        $items->where('status', $this->filter->getValue());

        return $next($items);
    }
}