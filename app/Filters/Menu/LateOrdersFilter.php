<?php

namespace App\Filters\Menu;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class LateOrdersFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $user = $this->filter->getValue()['user'] ?? null;
        
        if ($user && $user->allow_late_orders) {
            $items = $items->where('max_order_date', '>', Carbon::now());
        }

        return $next($items);
    }
}