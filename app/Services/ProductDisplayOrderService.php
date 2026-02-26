<?php

namespace App\Services;

use App\Actions\Products\ApplyDisplayOrderToPivotAction;
use App\Actions\Products\MarkMenuProductsOrderedAction;
use App\Jobs\ApplyProductDisplayOrderJob;
use App\Repositories\CategoryMenuRepository;
use App\Repositories\MenuRepository;
use Illuminate\Support\Collection;

class ProductDisplayOrderService
{
    public function __construct(
        private MenuRepository $menuRepository,
        private CategoryMenuRepository $categoryMenuRepository,
    ) {}

    /**
     * Get pending Cafe menus and dispatch ordering jobs for each.
     *
     * @return Collection The menus for which jobs were dispatched
     */
    public function dispatchOrderingJobs(int $limit): Collection
    {
        $menus = $this->menuRepository->getPendingCafeMenusForDisplayOrder($limit);

        foreach ($menus as $menu) {
            ApplyProductDisplayOrderJob::dispatch($menu->id);
        }

        return $menus;
    }

    /**
     * Process a single menu: apply product.display_order to category_menu_product.display_order
     * for all non-dynamic categories, then mark the menu as products_ordered.
     *
     * @return array{status: string, category_menus_processed?: int, products_updated: int}
     */
    public function processMenu(int $menuId): array
    {
        $menu = $this->menuRepository->findWithRole($menuId);

        if (! $menu) {
            return ['status' => 'not_found', 'products_updated' => 0];
        }

        $categoryMenus = $this->categoryMenuRepository->getNonDynamicWithProductsForMenu($menuId);

        if ($categoryMenus->isEmpty()) {
            MarkMenuProductsOrderedAction::execute(['menu_id' => $menuId]);

            return ['status' => 'no_categories', 'products_updated' => 0];
        }

        $totalUpdated = 0;

        foreach ($categoryMenus as $categoryMenu) {
            if ($categoryMenu->products->isNotEmpty()) {
                ApplyDisplayOrderToPivotAction::execute([
                    'category_menu_id' => $categoryMenu->id,
                ]);
                $totalUpdated += $categoryMenu->products->count();
            }
        }

        MarkMenuProductsOrderedAction::execute(['menu_id' => $menuId]);

        return [
            'status' => 'processed',
            'category_menus_processed' => $categoryMenus->count(),
            'products_updated' => $totalUpdated,
        ];
    }
}
