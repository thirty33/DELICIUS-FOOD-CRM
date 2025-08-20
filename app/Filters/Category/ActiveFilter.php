<?php

namespace App\Filters\Category;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class ActiveFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        // Apply active constraint - specify table to avoid ambiguous column
        $items->where('category_menu.is_active', true);

        return $next($items);
    }
}