<?php

namespace App\Filters\Order;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class UserSearchFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $searchTerm = $this->filter->getValue();

        if (!$searchTerm || !is_string($searchTerm)) {
            return $next($items);
        }

        // Search in user's name, nickname and email fields
        $items->whereHas('user', function (Builder $query) use ($searchTerm) {
            $query->where(function (Builder $q) use ($searchTerm) {
                $q->where('name', 'like', '%' . $searchTerm . '%')
                  ->orWhere('nickname', 'like', '%' . $searchTerm . '%')
                  ->orWhere('email', 'like', '%' . $searchTerm . '%');
            });
        });

        return $next($items);
    }
}