<?php

namespace App\Repositories;

use App\Contracts\HorecaLabelDataRepositoryInterface;
use App\Models\Order;
use Illuminate\Support\Collection;

class HorecaLabelDataRepository implements HorecaLabelDataRepositoryInterface
{
    /**
     * Get label data grouped by branch and max_quantity_horeca
     *
     * Business logic:
     * 1. Get all order lines from orders
     * 2. Filter products that have plated dishes (HORECA products with is_horeca = true)
     * 3. For each branch, calculate total quantity needed per ingredient
     * 4. Split labels based on max_quantity_horeca
     *
     * @param array $orderIds Array of order IDs
     * @return Collection
     */
    public function getHorecaLabelDataByOrders(array $orderIds): Collection
    {
        // Get orders with all necessary relationships
        $orders = Order::whereIn('id', $orderIds)
            ->with([
                'orderLines.product.platedDish.ingredients',
                'user.branch.company'
            ])
            ->get();

        // Collect all data grouped by branch and ingredient
        $groupedData = [];

        foreach ($orders as $order) {
            foreach ($order->orderLines as $orderLine) {
                // Skip if product doesn't have plated dish
                if (!$orderLine->product || !$orderLine->product->platedDish) {
                    continue;
                }

                $platedDish = $orderLine->product->platedDish;

                // Skip if plated dish is not HORECA (is_horeca = false)
                if (!$platedDish->is_horeca) {
                    continue;
                }

                $branch = $order->user->branch ?? null;

                // Skip if no branch found
                if (!$branch) {
                    continue;
                }

                $branchId = $branch->id;
                $branchFantasyName = $branch->fantasy_name ?? $branch->company->name ?? 'SIN SUCURSAL';

                // Process each ingredient in the plated dish
                foreach ($platedDish->ingredients as $ingredient) {
                    $key = "{$branchId}_{$ingredient->ingredient_name}";

                    // Initialize if not exists
                    if (!isset($groupedData[$key])) {
                        $groupedData[$key] = [
                            'ingredient_name' => $ingredient->ingredient_name,
                            'ingredient_product_code' => $this->extractProductCode($ingredient->ingredient_name),
                            'branch_id' => $branchId,
                            'branch_fantasy_name' => $branchFantasyName,
                            'measure_unit' => $ingredient->measure_unit,
                            'max_quantity_horeca' => $ingredient->max_quantity_horeca ?? 1000, // Default 1000 if null
                            'shelf_life' => $ingredient->shelf_life, // Shelf life in days from plated_dish_ingredients
                            'total_quantity_needed' => 0,
                        ];
                    }

                    // Add quantity: ingredient quantity per dish × order line quantity
                    $quantityPerDish = (float) $ingredient->quantity;
                    $dishesOrdered = (int) $orderLine->quantity;
                    $groupedData[$key]['total_quantity_needed'] += $quantityPerDish * $dishesOrdered;
                }
            }
        }

        // Calculate labels and weights for each group
        $labelData = collect($groupedData)->map(function ($item) {
            return $this->calculateLabelsAndWeights($item);
        })->sortBy('branch_id')->values();

        return $labelData;
    }

    /**
     * Calculate number of labels and individual weights based on max_quantity_horeca
     *
     * Examples:
     * - Total: 600 GR, Max: 1000 GR → 1 label [600]
     * - Total: 1500 GR, Max: 1000 GR → 2 labels [1000, 500]
     * - Total: 1200 GR, Max: 1000 GR → 2 labels [1000, 200]
     *
     * @param array $item
     * @return array
     */
    private function calculateLabelsAndWeights(array $item): array
    {
        $totalNeeded = $item['total_quantity_needed'];
        $maxQuantity = $item['max_quantity_horeca'];
        $weights = [];

        if ($totalNeeded <= $maxQuantity) {
            // Single label
            $weights[] = $totalNeeded;
        } else {
            // Multiple labels
            $remaining = $totalNeeded;

            while ($remaining > 0) {
                if ($remaining > $maxQuantity) {
                    $weights[] = $maxQuantity;
                    $remaining -= $maxQuantity;
                } else {
                    $weights[] = $remaining;
                    $remaining = 0;
                }
            }
        }

        $item['labels_count'] = count($weights);
        $item['weights'] = $weights;

        return $item;
    }

    /**
     * Extract product code from ingredient name
     *
     * Example: "MZC - CONSOME DE POLLO GRANEL" → "MZC"
     *
     * @param string $ingredientName
     * @return string|null
     */
    private function extractProductCode(string $ingredientName): ?string
    {
        // Check if ingredient name contains " - " separator
        if (str_contains($ingredientName, ' - ')) {
            return trim(explode(' - ', $ingredientName)[0]);
        }

        return null;
    }
}