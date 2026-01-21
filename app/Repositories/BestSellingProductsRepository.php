<?php

namespace App\Repositories;

use App\Contracts\BestSellingProductsRepositoryInterface;
use App\Models\OrderLine;
use Illuminate\Support\Collection;

class BestSellingProductsRepository implements BestSellingProductsRepositoryInterface
{
    /**
     * Get the best-selling products for a specific role within a date range.
     *
     * @param string $roleName The role name to filter users
     * @param string $startDate Start date for the query (inclusive)
     * @param string $endDate End date for the query (inclusive)
     * @param int $limit Number of top products to return
     * @return Collection Collection of product data with sales statistics
     */
    public function getBestSellingProductsByRole(
        string $roleName,
        string $startDate,
        string $endDate,
        int $limit = 10
    ): Collection {
        return OrderLine::query()
            ->with(['product.category'])
            ->whereHas('order', function ($orderQuery) use ($startDate, $endDate, $roleName) {
                $orderQuery
                    ->where('created_at', '>=', $startDate)
                    ->where('created_at', '<=', $endDate)
                    ->whereHas('user.roles', function ($roleQuery) use ($roleName) {
                        $roleQuery->where('name', $roleName);
                    });
            })
            ->get()
            ->groupBy('product_id')
            ->map(function ($orderLines, $productId) {
                $firstLine = $orderLines->first();
                $product = $firstLine->product;

                return (object) [
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'category_name' => $product->category?->name,
                    'total_quantity_sold' => $orderLines->sum('quantity'),
                    'total_orders' => $orderLines->pluck('order_id')->unique()->count(),
                ];
            })
            ->sortByDesc('total_quantity_sold')
            ->take($limit)
            ->values();
    }

    /**
     * Get the best-selling product IDs for a specific role within a date range.
     * Products are grouped by category, with categories ordered by total sales (descending).
     * Within each category, products are ordered by their individual sales (descending).
     *
     * @param string $roleName The role name to filter users
     * @param string $startDate Start date for the query (inclusive)
     * @param string $endDate End date for the query (inclusive)
     * @param int $limit Number of top products to return
     * @return array Array of product IDs ordered by category sales then product sales
     */
    public function getBestSellingProductIdsByRole(
        string $roleName,
        string $startDate,
        string $endDate,
        int $limit = 10
    ): array {
        $products = $this->getBestSellingProductsByRole($roleName, $startDate, $endDate, $limit);

        // Group products by category
        $productsByCategory = $products->groupBy('category_name');

        // Calculate total sales per category and sort categories by total sales descending
        $categorySales = $productsByCategory->map(function ($categoryProducts) {
            return $categoryProducts->sum('total_quantity_sold');
        })->sortDesc();

        // Build ordered product IDs: categories by total sales, products within each category by individual sales
        $orderedProductIds = [];
        foreach ($categorySales->keys() as $categoryName) {
            $categoryProducts = $productsByCategory[$categoryName]
                ->sortByDesc('total_quantity_sold')
                ->pluck('product_id')
                ->toArray();
            $orderedProductIds = array_merge($orderedProductIds, $categoryProducts);
        }

        return $orderedProductIds;
    }
}