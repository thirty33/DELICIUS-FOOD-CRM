<?php

namespace App\Filters\Category;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class SortFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $filterData = $this->filter->getValue();
        
        // Only apply default ordering if no category group ordering was applied
        if (!$filterData || !isset($filterData['skip_default_sort'])) {
            $items->orderBy('category_menu.display_order', 'asc');
        }

        return $next($items);
    }
}