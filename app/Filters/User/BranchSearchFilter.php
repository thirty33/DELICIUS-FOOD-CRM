<?php

namespace App\Filters\User;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class BranchSearchFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $search = $this->filter->getValue()['search'] ?? null;

        if ($search) {
            $items->whereHas('branch', function (Builder $query) use ($search) {
                $query->where('fantasy_name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('branch_code', 'like', "%{$search}%");
            });
        }

        return $next($items);
    }
}
