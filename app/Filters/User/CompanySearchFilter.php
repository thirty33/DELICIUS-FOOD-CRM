<?php

namespace App\Filters\User;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class CompanySearchFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $search = $this->filter->getValue()['search'] ?? null;

        if ($search) {
            $items->whereHas('company', function (Builder $query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('fantasy_name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%")
                    ->orWhere('company_code', 'like', "%{$search}%");
            });
        }

        return $next($items);
    }
}
