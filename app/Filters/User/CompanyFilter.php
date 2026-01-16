<?php

namespace App\Filters\User;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class CompanyFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $masterUser = $this->filter->getValue()['master_user'] ?? null;

        if ($masterUser && !$masterUser->super_master_user) {
            $items->where('company_id', $masterUser->company_id);
        }

        return $next($items);
    }
}
