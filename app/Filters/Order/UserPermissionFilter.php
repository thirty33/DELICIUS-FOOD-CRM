<?php

namespace App\Filters\Order;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class UserPermissionFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $permissionName = $this->filter->getValue();

        if (!$permissionName || !is_string($permissionName)) {
            return $next($items);
        }

        $items->whereHas('user.permissions', function (Builder $query) use ($permissionName) {
            $query->where('name', $permissionName);
        });

        return $next($items);
    }
}