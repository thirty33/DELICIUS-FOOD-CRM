<?php

namespace App\Filters\Order;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

final class CompanyFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $filterData = $this->filter->getValue();

        // Skip filter for super_master_user (can see all companies)
        if (isset($filterData['super_master_user']) && $filterData['super_master_user']) {
            return $next($items);
        }

        // Only apply company filter if user is master
        if (!$filterData || !is_array($filterData) || !isset($filterData['master_user']) || !$filterData['master_user']) {
            return $next($items);
        }

        $companyId = $filterData['company_id'];
        
        $items->whereHas('user', function (Builder $query) use ($companyId) {
            $query->where('company_id', $companyId);
        });

        return $next($items);
    }
}