<?php

namespace App\Filters\Menu;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class PublicationDateFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $date = $this->filter->getValue()['date'] ?? null;
        
        if ($date) {
            $items = $items->where('publication_date', '>=', $date);
        }

        return $next($items);
    }
}