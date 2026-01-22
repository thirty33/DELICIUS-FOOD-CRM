<?php

namespace App\Jobs;

use App\Models\Menu;
use App\Models\CategoryMenu;
use App\Models\OrderLine;
use App\Enums\OrderStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderCategoryMenuProductsByBestSellingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    protected int $menuId;
    protected string $startDate;
    protected string $endDate;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $menuId,
        string $startDate,
        string $endDate
    ) {
        $this->menuId = $menuId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting OrderCategoryMenuProductsByBestSellingJob', [
            'menu_id' => $this->menuId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);

        $menu = Menu::find($this->menuId);

        if (!$menu) {
            Log::warning('Menu not found', ['menu_id' => $this->menuId]);
            return;
        }

        // Get category_menus that are NOT related to dynamic categories
        $categoryMenus = CategoryMenu::where('menu_id', $menu->id)
            ->whereHas('category', function ($query) {
                $query->where('is_dynamic', false);
            })
            ->with(['products', 'category'])
            ->get();

        if ($categoryMenus->isEmpty()) {
            Log::info('No non-dynamic category menus found for menu', [
                'menu_id' => $this->menuId,
            ]);
            $this->markMenuAsOrdered($menu);
            return;
        }

        $totalUpdated = 0;

        foreach ($categoryMenus as $categoryMenu) {
            $updatedCount = $this->orderProductsForCategoryMenu($categoryMenu);
            $totalUpdated += $updatedCount;
        }

        // Mark menu as ordered
        $this->markMenuAsOrdered($menu);

        Log::info('OrderCategoryMenuProductsByBestSellingJob completed', [
            'menu_id' => $this->menuId,
            'category_menus_processed' => $categoryMenus->count(),
            'total_products_updated' => $totalUpdated,
        ]);
    }

    /**
     * Order products within a category_menu based on sales data.
     */
    protected function orderProductsForCategoryMenu(CategoryMenu $categoryMenu): int
    {
        $productIds = $categoryMenu->products->pluck('id')->toArray();

        if (empty($productIds)) {
            return 0;
        }

        // Get sales data for products in the date range
        $salesData = OrderLine::query()
            ->select('product_id', DB::raw('SUM(quantity) as total_sold'))
            ->whereIn('product_id', $productIds)
            ->whereHas('order', function ($query) {
                $query->where('status', OrderStatus::PROCESSED->value)
                    ->whereBetween('created_at', [$this->startDate, $this->endDate]);
            })
            ->groupBy('product_id')
            ->orderByDesc('total_sold')
            ->get()
            ->pluck('total_sold', 'product_id')
            ->toArray();

        // Build ordered product list: products without sales first, then products with sales (least to most sold)
        $productsWithSales = [];
        $productsWithoutSales = [];

        foreach ($productIds as $productId) {
            if (isset($salesData[$productId]) && $salesData[$productId] > 0) {
                $productsWithSales[$productId] = $salesData[$productId];
            } else {
                $productsWithoutSales[] = $productId;
            }
        }

        // Sort products with sales by total_sold ascending (least sold first)
        asort($productsWithSales);

        // Merge: products without sales first, then products with sales (least to most sold)
        $orderedProductIds = array_merge($productsWithoutSales, array_keys($productsWithSales));

        // Update display_order for each product
        $displayOrder = 1;
        foreach ($orderedProductIds as $productId) {
            DB::table('category_menu_product')
                ->where('category_menu_id', $categoryMenu->id)
                ->where('product_id', $productId)
                ->update(['display_order' => $displayOrder]);
            $displayOrder++;
        }

        Log::debug('Products ordered for category_menu', [
            'category_menu_id' => $categoryMenu->id,
            'category_name' => $categoryMenu->category->name ?? 'N/A',
            'products_count' => count($orderedProductIds),
            'products_with_sales' => count($productsWithSales),
        ]);

        return count($orderedProductIds);
    }

    /**
     * Mark the menu as having its products ordered.
     */
    protected function markMenuAsOrdered(Menu $menu): void
    {
        $menu->update(['products_ordered' => true]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('OrderCategoryMenuProductsByBestSellingJob failed', [
            'menu_id' => $this->menuId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}