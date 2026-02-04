<?php

namespace App\Enums\Filters;

use App\Filters\Company\RoleFilter;
use App\Filters\Company\PermissionFilter;
use App\Filters\Filter;
use App\Filters\FilterValue;

enum CompanyFilters: string
{
    case Role = 'role';
    case Permission = 'permission';

    public function create(FilterValue $filter): Filter
    {
        return match ($this) {
            self::Role => new RoleFilter(filter: $filter),
            self::Permission => new PermissionFilter(filter: $filter),
        };
    }
}