<?php

namespace App\Filters\Category;

use App\Filters\Filter;
use App\Models\PriceListLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

final class PriceListFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $filterData = $this->filter->getValue();

        if (! $filterData || ! is_array($filterData) || ! isset($filterData['user'])) {
            return $next($items);
        }

        $user = $filterData['user'];

        // OLD CODE - Filtered at CATEGORY level (caused bug where categories with products
        // not in menu but with prices would still show up)
        // $items->whereIn(
        //     'category_menu.category_id',
        //     Product::whereIn(
        //         'id',
        //         PriceListLine::where('price_list_id', $user->company->price_list_id)
        //             ->where('active', true)
        //             ->select('product_id')
        //     )->select('category_id')
        // );

        // UPDATED CODE - Filter based on show_all_products flag
        //
        // Case 1: show_all_products = true
        //   - Check if category has ANY products with price list lines
        //   - Products don't need to be in the pivot table
        //   - Example: Menu 209 with User HEY.FRIA
        //
        // Case 2: show_all_products = false
        //   - Check if products IN PIVOT have price list lines
        //   - Only products specifically added to menu count
        //   - Example: Menu 188 with specific products selected
        //
        $items->where(function ($query) use ($user) {
            // Case 1: show_all_products = true → Check category has ACTIVE products with prices
            $query->where(function ($subQuery) use ($user) {
                $subQuery->where('show_all_products', true)
                    ->whereHas('category', function ($categoryQuery) use ($user) {
                        $categoryQuery->whereHas('products', function ($productQuery) use ($user) {
                            $productQuery->where('active', true)
                                ->whereHas('priceListLines', function ($priceQuery) use ($user) {
                                    $priceQuery->where('active', true)
                                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                            $priceListQuery->where('id', $user->company->price_list_id);
                                        });
                                });
                        });
                    });
            })
            // Case 2: show_all_products = false → Check pivot products
                ->orWhere(function ($subQuery) use ($user) {
                    $subQuery->where('show_all_products', false)
                        ->whereHas('products', function ($pivotQuery) use ($user) {
                            $pivotQuery->whereHas('priceListLines', function ($priceListQuery) use ($user) {
                                $priceListQuery->where('active', true)
                                    ->where('price_list_id', $user->company->price_list_id);
                            });
                        });
                });
        });

        return $next($items);
    }
}
