<?php

namespace App\Filters\Company;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class PermissionFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $permissionName = $this->filter->getValue()['permission'] ?? null;

        if ($permissionName) {
            $items->whereHas('users.permissions', function (Builder $query) use ($permissionName) {
                $query->where('name', $permissionName);
            });
        }

        return $next($items);
    }
}