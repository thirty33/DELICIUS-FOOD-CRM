<?php

namespace App\Filters\User;

use App\Filters\Filter;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

class SortFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $items->orderBy(
            Company::select('name')
                ->whereColumn('companies.id', 'users.company_id')
                ->limit(1),
            'asc'
        )
        ->orderBy(
            Branch::select('fantasy_name')
                ->whereColumn('branches.id', 'users.branch_id')
                ->limit(1),
            'asc'
        )
        ->orderBy('email', 'asc')
        ->orderBy('nickname', 'asc');

        return $next($items);
    }
}
