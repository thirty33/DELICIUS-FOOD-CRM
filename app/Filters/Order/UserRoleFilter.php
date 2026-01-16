<?php

namespace App\Filters\Order;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class UserRoleFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $roleName = $this->filter->getValue();

        if (!$roleName || !is_string($roleName)) {
            return $next($items);
        }

        $items->whereHas('user.roles', function (Builder $query) use ($roleName) {
            $query->where('name', $roleName);
        });

        return $next($items);
    }
}