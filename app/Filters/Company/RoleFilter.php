<?php

namespace App\Filters\Company;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class RoleFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $roleName = $this->filter->getValue()['role'] ?? null;

        if ($roleName) {
            $items->whereHas('users.roles', function (Builder $query) use ($roleName) {
                $query->where('name', $roleName);
            });
        }

        return $next($items);
    }
}