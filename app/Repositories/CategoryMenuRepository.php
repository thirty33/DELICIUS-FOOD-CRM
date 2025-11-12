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
     * @param string|null $priorityGroup The category group name (e.g., "ensaladas", "gohan")
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getCategoryMenusForUser(Menu $menu, User $user, ?string $priorityGroup = null, int $perPage = 15): LengthAwarePaginator
    {
        $publicationDate = Carbon::parse($menu->publication_date);
        $weekday = ucfirst(strtolower($publicationDate->isoFormat('dddd')));
        $weekdayInEnglish = Weekday::fromSpanish($weekday);

        // Build base query with all eager loaded relationships
        $baseQuery = CategoryMenu::with([
            'category' => function ($query) use ($user, $weekdayInEnglish) {
                // Filter categories that have products with price list lines
                $query->whereHas('products', function ($priceListQuery) use ($user) {
                    $priceListQuery->where('active', true)
                        ->whereHas('priceListLines', function ($subQuery) use ($user) {
                            $subQuery->where('active', true)
                                ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                    $priceListQuery->where('id', $user->company->price_list_id);
                                });
                        });
                });

                // Eager load products with price list lines
                $query->with(['products' => function ($query) use ($user) {
                    $query->where('active', true)
                        ->whereHas('priceListLines', function ($subQuery) use ($user) {
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
                $query->where('active', true)
                    ->whereHas('priceListLines', function ($subQuery) use ($user) {
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

        // UPDATED CODE - Filter based on show_all_products flag (same logic as PriceListFilter)
        //
        // Case 1: show_all_products = true
        //   - Check if category has ANY products with price list lines
        //   - Products don't need to be in the pivot table
        //   - Example: Menu 211 with User HEY.FRIA (Convenio Consolidado)
        //
        // Case 2: show_all_products = false
        //   - Check if products IN PIVOT have price list lines
        //   - Only products specifically added to menu count
        //   - Example: Menu with specific products selected
        //
        return $menu->categoryMenus()
            ->where('is_active', true)
            ->where(function ($query) use ($user) {
                // Case 1: show_all_products = true → Check category products
                $query->where(function ($subQuery) use ($user) {
                    $subQuery->where('show_all_products', true)
                        ->whereHas('category.products', function ($productQuery) use ($user) {
                            $productQuery->where('active', true)
                                ->whereHas('priceListLines', function ($priceQuery) use ($user) {
                                    $priceQuery->where('active', true)
                                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                            $priceListQuery->where('id', $user->company->price_list_id);
                                        });
                                });
                        });
                })
                // Case 2: show_all_products = false → Check pivot products
                ->orWhere(function ($subQuery) use ($user) {
                    $subQuery->where('show_all_products', false)
                        ->whereHas('products', function ($pivotQuery) use ($user) {
                            $pivotQuery->where('active', true)
                                ->whereHas('priceListLines', function ($priceListQuery) use ($user) {
                                    $priceListQuery->where('active', true)
                                        ->where('price_list_id', $user->company->price_list_id);
                                });
                        });
                });
            })
            ->orderedByDisplayOrder()
            ->get();
    }
}
