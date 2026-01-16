<?php

namespace App\Enums\Filters;

use App\Filters\Filter;
use App\Filters\FilterValue;
use App\Filters\User\ExcludeMasterUsersFilter;
use App\Filters\User\CompanyFilter;
use App\Filters\User\CompanySearchFilter;
use App\Filters\User\BranchSearchFilter;
use App\Filters\User\UserSearchFilter;
use App\Filters\User\SortFilter;

enum UserFilters: string
{
    case ExcludeMasterUsers = 'exclude_master_users';
    case Company = 'company';
    case CompanySearch = 'company_search';
    case BranchSearch = 'branch_search';
    case UserSearch = 'user_search';
    case Sort = 'sort';

    public function create(FilterValue $filter): Filter
    {
        return match ($this) {
            self::ExcludeMasterUsers => new ExcludeMasterUsersFilter(filter: $filter),
            self::Company => new CompanyFilter(filter: $filter),
            self::CompanySearch => new CompanySearchFilter(filter: $filter),
            self::BranchSearch => new BranchSearchFilter(filter: $filter),
            self::UserSearch => new UserSearchFilter(filter: $filter),
            self::Sort => new SortFilter(filter: $filter),
        };
    }
}
