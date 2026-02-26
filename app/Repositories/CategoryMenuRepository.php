<?php

namespace App\Repositories;

use App\Classes\UserPermissions;
use App\Enums\Filters\CategoryFilters;
use App\Enums\Weekday;
use App\Filters\FilterValue;
use App\Models\CategoryMenu;
use App\Models\Menu;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;

class CategoryMenuRepository
{
    /**
     * Get category menus for a specific menu with all relationships and filters applied.
     *
     * @param  string|null  $priorityGroup  The category group name (e.g., "ensaladas", "gohan")
     */
    public function getCategoryMenusForUser(Menu $menu, User $user, ?string $priorityGroup = null, int $perPage = 15): LengthAwarePaginator
    {
        $publicationDate = Carbon::parse($menu->publication_date);
        $weekday = ucfirst(strtolower($publicationDate->isoFormat('dddd')));
        $weekdayInEnglish = Weekday::fromSpanish($weekday);

        // Build base query with all eager loaded relationships
        $baseQuery = CategoryMenu::with([
            'category' => function ($query) use ($user, $weekdayInEnglish, $menu) {
                // Filter categories that have products with price list lines
                // OR are dynamic categories (which aggregate products from other categories)
                $query->where(function ($q) use ($user) {
                    $q->where('is_dynamic', true)
                        ->orWhereHas('products', function ($priceListQuery) use ($user) {
                            $priceListQuery->where('active', true)
                                ->whereHas('priceListLines', function ($subQuery) use ($user) {
                                    $subQuery->where('active', true)
                                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                            $priceListQuery->where('id', $user->company->price_list_id);
                                        });
                                });
                        });
                });

                // Eager load products with price list lines
                // Order by display_order from pivot table (if exists), fallback to 9999 for legacy data
                $query->with(['products' => function ($productQuery) use ($user, $menu, $weekdayInEnglish) {
                    $productQuery->where('active', true)
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
                        ->with(['ingredients'])
                        ->orderByRaw('(
                            SELECT COALESCE(cmp.display_order, 9999)
                            FROM category_menu_product cmp
                            INNER JOIN category_menu cm ON cmp.category_menu_id = cm.id
                            WHERE cmp.product_id = products.id
                            AND cm.menu_id = ?
                            AND cm.category_id = products.category_id
                            LIMIT 1
                        ) ASC', [$menu->id]);

                    // For Cafe users: eager load product's original category with its categoryLines
                    // This is needed for dynamic categories to show availability text per product
                    if (UserPermissions::IsCafe($user)) {
                        $productQuery->with(['category.categoryLines' => function ($clQuery) use ($weekdayInEnglish) {
                            $clQuery->where('weekday', $weekdayInEnglish->value)->where('active', 1);
                        }]);
                    }
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
            // Order by display_order from pivot table (for show_all_products = false)
            'products' => function ($query) use ($user, $weekdayInEnglish) {
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
                    }])->with(['ingredients'])
                    ->orderBy('category_menu_product.display_order', 'asc');

                // For Cafe users: eager load product's original category with its categoryLines
                // This is needed for dynamic categories to show availability text per product
                if (UserPermissions::IsCafe($user)) {
                    $query->with(['category.categoryLines' => function ($clQuery) use ($weekdayInEnglish) {
                        $clQuery->where('weekday', $weekdayInEnglish->value)->where('active', 1);
                    }]);
                }
            },
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

    /**
     * Get all active product IDs for a category.
     *
     * This method is used during CategoryMenu import when show_all_products = true.
     * It returns the IDs of all active products that belong to the specified category,
     * which should be attached to the category_menu_product pivot table.
     *
     * @param  int  $categoryId  The category ID to get products for
     * @return array Array of product IDs
     */
    public function getActiveProductIdsForCategory(int $categoryId): array
    {
        return Product::where('category_id', $categoryId)
            ->where('active', true)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get non-dynamic category menus for a given menu, with products and category.
     */
    public function getNonDynamicWithProductsForMenu(int $menuId): Collection
    {
        return CategoryMenu::where('menu_id', $menuId)
            ->whereHas('category', fn ($q) => $q->where('is_dynamic', false))
            ->with(['products', 'category'])
            ->get();
    }

    /**
     * Create or update a CategoryMenu and sync its products.
     *
     * @param  int  $menuId  The menu ID
     * @param  int  $categoryId  The category ID
     * @param  array  $productIds  Array of product IDs to sync (ordered by display position)
     * @param  array  $attributes  Additional attributes for the CategoryMenu
     */
    public function createOrUpdateWithProducts(
        int $menuId,
        int $categoryId,
        array $productIds,
        array $attributes = []
    ): CategoryMenu {
        $defaultAttributes = [
            'show_all_products' => false,
            'display_order' => 0,
            'mandatory_category' => false,
            'is_active' => true,
        ];

        $categoryMenu = CategoryMenu::updateOrCreate(
            [
                'menu_id' => $menuId,
                'category_id' => $categoryId,
            ],
            array_merge($defaultAttributes, $attributes)
        );

        $syncData = [];
        foreach ($productIds as $position => $productId) {
            $syncData[$productId] = ['display_order' => $position + 1];
        }

        $categoryMenu->products()->sync($syncData);

        return $categoryMenu;
    }
}
