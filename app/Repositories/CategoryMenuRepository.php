<?php

namespace App\Repositories;

use App\Models\CategoryMenu;
use App\Models\Menu;
use App\Models\User;
use App\Enums\Weekday;
use Carbon\Carbon;
use Illuminate\Pipeline\Pipeline;
use App\Filters\FilterValue;
use App\Enums\Filters\CategoryFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CategoryMenuRepository
{
    /**
     * Get category menus for a specific menu with all relationships and filters applied.
     *
     * @param Menu $menu
     * @param User $user
     * @param int|null $priorityGroup
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getCategoryMenusForUser(Menu $menu, User $user, ?int $priorityGroup = null, int $perPage = 15): LengthAwarePaginator
    {
        $publicationDate = Carbon::parse($menu->publication_date);
        $weekday = ucfirst(strtolower($publicationDate->isoFormat('dddd')));
        $weekdayInEnglish = Weekday::fromSpanish($weekday);

        // Build base query with all eager loaded relationships
        $baseQuery = CategoryMenu::with([
            'category' => function ($query) use ($user, $weekdayInEnglish) {
                // Filter categories that have products with price list lines
                $query->whereHas('products', function ($priceListQuery) use ($user) {
                    $priceListQuery->whereHas('priceListLines', function ($subQuery) use ($user) {
                        $subQuery->where('active', true)
                            ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                $priceListQuery->where('id', $user->company->price_list_id);
                            });
                    });
                });

                // Eager load products with price list lines
                $query->with(['products' => function ($query) use ($user) {
                    $query->whereHas('priceListLines', function ($subQuery) use ($user) {
                        $subQuery->where('active', true)
                            ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                $priceListQuery->where('id', $user->company->price_list_id);
                            });
                    })->with(['priceListLines' => function ($query) use ($user) {
                        $query->where('active', true)
                            ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                $priceListQuery->where('id', $user->company->price_list_id);
                            });
                    }])
                        ->with(['ingredients']);
                }]);

                // Eager load category lines for the specific weekday
                $query->with(['categoryLines' => function ($query) use ($weekdayInEnglish) {
                    $query->where('weekday', $weekdayInEnglish->value)->where('active', 1);
                }]);

                // Eager load subcategories
                $query->with(['subcategories']);

                // Eager load category user lines for the specific weekday and user
                $query->with(['categoryUserLines' => function ($query) use ($weekdayInEnglish, $user) {
                    $query->where('weekday', $weekdayInEnglish->value)
                        ->where('active', 1)
                        ->where('user_id', $user->id);
                }]);
            },
            'menu',
            // Eager load products directly on CategoryMenu with price list lines
            'products' => function ($query) use ($user) {
                $query->whereHas('priceListLines', function ($subQuery) use ($user) {
                    $subQuery->where('active', true)
                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                            $priceListQuery->where('id', $user->company->price_list_id);
                        });
                })->with(['priceListLines' => function ($query) use ($user) {
                    $query->where('active', true)
                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                            $priceListQuery->where('id', $user->company->price_list_id);
                        });
                }])->with(['ingredients']);
            }
        ]);

        // Apply filters using pipeline pattern
        $filters = [
            CategoryFilters::PriceList->create(new FilterValue(['user' => $user])),
            CategoryFilters::MenuContext->create(new FilterValue(['menu' => $menu])),
            CategoryFilters::Active->create(new FilterValue(null)),
            CategoryFilters::CategoryGroupOrder->create(new FilterValue($priorityGroup)),
            CategoryFilters::Sort->create(new FilterValue(['skip_default_sort' => (bool) $priorityGroup])),
        ];

        return app(Pipeline::class)
            ->send($baseQuery)
            ->through($filters)
            ->thenReturn()
            ->paginate($perPage);
    }

    /**
     * Get category menus filtered for order validations.
     * Returns only active category menus with products that have price list lines.
     * This method does NOT use eager loading to keep the query simple for validations.
     *
     * @param Menu $menu
     * @param User $user
     * @return Collection
     */
    public function getCategoryMenusForValidation(Menu $menu, User $user): Collection
    {
        // OLD CODE - Filtered at CATEGORY level (caused bug where categories with products
        // not in menu but with prices would still show up)
        // return $menu->categoryMenus()
        //     ->where('is_active', true)
        //     ->whereHas('category.products.priceListLines', function ($query) use ($user) {
        //         $query->where('active', true)
        //             ->whereHas('priceList', function ($priceListQuery) use ($user) {
        //                 $priceListQuery->where('id', $user->company->price_list_id);
        //             });
        //     })
        //     ->orderedByDisplayOrder()
        //     ->get();

        // NEW CODE - Filter at MENU-PIVOT level
        // Only include CategoryMenus where products in the pivot table have active price list lines
        return $menu->categoryMenus()
            ->where('is_active', true)
            ->whereHas('products', function ($query) use ($user) {
                $query->whereHas('priceListLines', function ($priceListQuery) use ($user) {
                    $priceListQuery->where('active', true)
                        ->where('price_list_id', $user->company->price_list_id);
                });
            })
            ->orderedByDisplayOrder()
            ->get();
    }
}
