<?php

namespace App\Filters\Category;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class MenuContextFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $filterData = $this->filter->getValue();
        
        if (!$filterData || !is_array($filterData) || !isset($filterData['menu'])) {
            return $next($items);
        }

        $menu = $filterData['menu'];
        
        // Apply menu constraint - specify table to avoid ambiguous column
        $items->where('category_menu.menu_id', $menu->id);

        return $next($items);
    }
}