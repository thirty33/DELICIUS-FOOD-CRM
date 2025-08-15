<?php

namespace App\Filters\Order;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class SortFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $sortData = $this->filter->getValue();

        // Check if we have sort data
        if (!$sortData || !is_array($sortData)) {
            return $next($items);
        }

        $column = $sortData['column'] ?? null;
        $direction = $sortData['direction'] ?? 'asc';

        // Validate direction
        if (!in_array(strtolower($direction), ['asc', 'desc'])) {
            $direction = 'asc';
        }

        // Apply sorting if column is provided
        if ($column) {
            $items->orderBy($column, $direction);
        }

        return $next($items);
    }
}