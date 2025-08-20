<?php

namespace App\Filters\Category;

use App\Filters\Filter;
use App\Models\Product;
use App\Models\PriceListLine;
use Illuminate\Database\Eloquent\Builder;

final class PriceListFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $filterData = $this->filter->getValue();
        
        if (!$filterData || !is_array($filterData) || !isset($filterData['user'])) {
            return $next($items);
        }

        $user = $filterData['user'];
        
        // Apply price list constraint to main query - specify table to avoid ambiguous column
        $items->whereIn(
            'category_menu.category_id',
            Product::whereIn(
                'id',
                PriceListLine::where('price_list_id', $user->company->price_list_id)
                    ->where('active', true)
                    ->select('product_id')
            )->select('category_id')
        );

        return $next($items);
    }
}