<?php

namespace App\Filters\Menu;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class ActiveFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $filteredItems = $items->where('active', 1);
        return $next($filteredItems);
    }
}