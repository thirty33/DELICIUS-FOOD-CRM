<?php

namespace App\Filters\Order;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class UserFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $userId = $this->filter->getValue();

        if (!$userId) {
            return $next($items);
        }

        $items->where('user_id', $userId);

        return $next($items);
    }
}