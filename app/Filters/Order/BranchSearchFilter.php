<?php

namespace App\Filters\Order;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class BranchSearchFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $searchTerm = $this->filter->getValue();

        if (!$searchTerm || !is_string($searchTerm)) {
            return $next($items);
        }

        // Search in branch's address, shipping_address and fantasy_name fields
        $items->orWhereHas('user.branch', function (Builder $query) use ($searchTerm) {
            $query->where(function (Builder $q) use ($searchTerm) {
                $q->where('address', 'like', '%' . $searchTerm . '%')
                  ->orWhere('shipping_address', 'like', '%' . $searchTerm . '%')
                  ->orWhere('fantasy_name', 'like', '%' . $searchTerm . '%');
            });
        });

        return $next($items);
    }
}