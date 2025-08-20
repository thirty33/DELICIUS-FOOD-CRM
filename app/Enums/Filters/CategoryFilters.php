<?php

namespace App\Enums\Filters;

use App\Filters\Filter;
use App\Filters\FilterValue;
use App\Filters\Category\PriceListFilter;
use App\Filters\Category\MenuContextFilter;
use App\Filters\Category\ActiveFilter;
use App\Filters\Category\SortFilter;
use App\Filters\Category\CategoryGroupOrderFilter;

enum CategoryFilters: string
{
    case PriceList = 'price_list';
    case MenuContext = 'menu_context';
    case Active = 'active';
    case CategoryGroupOrder = 'category_group_order';
    case Sort = 'sort';

    public function create(FilterValue $filter): Filter
    {
        return match ($this) {
            self::PriceList => new PriceListFilter(filter: $filter),
            self::MenuContext => new MenuContextFilter(filter: $filter),
            self::Active => new ActiveFilter(filter: $filter),
            self::CategoryGroupOrder => new CategoryGroupOrderFilter(filter: $filter),
            self::Sort => new SortFilter(filter: $filter),
        };
    }
}