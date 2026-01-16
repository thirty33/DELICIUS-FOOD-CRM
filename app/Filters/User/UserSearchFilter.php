<?php

namespace App\Filters\User;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class UserSearchFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $search = $this->filter->getValue()['search'] ?? null;

        if ($search) {
            $items->where(function (Builder $query) use ($search) {
                $query->where('nickname', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $next($items);
    }
}
