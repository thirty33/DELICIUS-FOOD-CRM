<?php

namespace App\Filters\Menu;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class DateRangeFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $startDate = $this->filter->getValue()['start_date'] ?? null;
        $endDate = $this->filter->getValue()['end_date'] ?? null;

        if ($startDate && $endDate) {
            $items->whereBetween('publication_date', [$startDate, $endDate]);
        } elseif ($startDate) {
            $items->where('publication_date', '>=', $startDate);
        } elseif ($endDate) {
            $items->where('publication_date', '<=', $endDate);
        }

        return $next($items);
    }
}