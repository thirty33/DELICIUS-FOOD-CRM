<?php

namespace App\Enums\Filters;

use App\Filters\Filter;
use App\Filters\FilterValue;
use App\Filters\Order\TimePeriodFilter;
use App\Filters\Order\OrderStatusFilter;
use App\Filters\Order\CompanyFilter;
use App\Filters\Order\UserFilter;
use App\Filters\Order\SortFilter;
use App\Filters\Order\UserSearchFilter;
use App\Filters\Order\BranchSearchFilter;

enum OrderFilters: string
{
    case TimePeriod = 'time_period';
    
    case OrderStatus = 'order_status';
    
    case Company = 'company';
    
    case User = 'user';
    
    case Sort = 'sort';
    
    case UserSearch = 'user_search';
    
    case BranchSearch = 'branch_search';

    public function create(FilterValue $filter): Filter
    {
        return match ($this)
        {
            self::TimePeriod => new TimePeriodFilter(filter: $filter),
            self::OrderStatus => new OrderStatusFilter(filter: $filter),
            self::Company => new CompanyFilter(filter: $filter),
            self::User => new UserFilter(filter: $filter),
            self::Sort => new SortFilter(filter: $filter),
            self::UserSearch => new UserSearchFilter(filter: $filter),
            self::BranchSearch => new BranchSearchFilter(filter: $filter),
        };
    }
}