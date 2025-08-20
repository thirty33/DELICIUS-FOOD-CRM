<?php

namespace App\Filters\Category;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class CategoryGroupOrderFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $filterData = $this->filter->getValue();
        
        // Skip if no priority group specified
        if (!$filterData || !is_string($filterData)) {
            return $next($items);
        }

        $priorityGroup = $filterData; // e.g., "ensaladas"
        
        // Apply category group priority ordering
        $items->join('categories', 'category_menu.category_id', '=', 'categories.id')
            ->leftJoin('category_category_group', 'categories.id', '=', 'category_category_group.category_id')
            ->leftJoin('category_groups', 'category_category_group.category_group_id', '=', 'category_groups.id')
            ->orderByRaw("
                CASE 
                    WHEN category_groups.name = ? THEN 0 
                    ELSE 1 
                END
            ", [$priorityGroup])
            ->orderBy('category_menu.display_order', 'asc')
            ->select('category_menu.*'); // Ensure we only select CategoryMenu fields

        return $next($items);
    }
}