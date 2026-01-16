<?php

namespace App\Filters\User;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class ExcludeMasterUsersFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $items->where('master_user', false);

        return $next($items);
    }
}
