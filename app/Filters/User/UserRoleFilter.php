<?php

namespace App\Filters\User;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class UserRoleFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $roleName = $this->filter->getValue()['role'] ?? null;

        if ($roleName) {
            $items->whereHas('roles', function (Builder $query) use ($roleName) {
                $query->where('name', $roleName);
            });
        }

        return $next($items);
    }
}
