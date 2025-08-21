<?php

namespace App\Filters\Menu;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class SortFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $field = $this->filter->getValue()['field'] ?? 'publication_date';
        $direction = $this->filter->getValue()['direction'] ?? 'asc';
        
        $items = $items->orderBy($field, $direction);
        
        return $next($items);
    }
}