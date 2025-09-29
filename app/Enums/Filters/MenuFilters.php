<?php

namespace App\Enums\Filters;

use App\Filters\Filter;
use App\Filters\FilterValue;
use App\Filters\Menu\ActiveFilter;
use App\Filters\Menu\PublicationDateFilter;
use App\Filters\Menu\RolePermissionFilter;
use App\Filters\Menu\LateOrdersFilter;
use App\Filters\Menu\SortFilter;
use App\Filters\Menu\WeekendDispatchFilter;
use App\Filters\Menu\CompanyAccessFilter;

enum MenuFilters: string
{
    case Active = 'active';
    case PublicationDate = 'publication_date';
    case RolePermission = 'role_permission';
    case LateOrders = 'late_orders';
    case Sort = 'sort';
    case WeekendDispatch = 'weekend_dispatch';
    case CompanyAccess = 'company_access';

    public function create(FilterValue $filter): Filter
    {
        return match ($this) {
            self::Active => new ActiveFilter(filter: $filter),
            self::PublicationDate => new PublicationDateFilter(filter: $filter),
            self::RolePermission => new RolePermissionFilter(filter: $filter),
            self::LateOrders => new LateOrdersFilter(filter: $filter),
            self::Sort => new SortFilter(filter: $filter),
            self::WeekendDispatch => new WeekendDispatchFilter(filter: $filter),
            self::CompanyAccess => new CompanyAccessFilter(filter: $filter),
        };
    }
}