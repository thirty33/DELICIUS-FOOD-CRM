<?php

namespace App\Repositories;

use App\Contracts\HorecaLabelDataRepositoryInterface;
use App\Models\AdvanceOrder;
use App\Models\Order;
use App\Models\ReportGrouper;
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

                // Get product info for sorting by product
                $product = $orderLine->product;
                $productId = $product->id;
                $productName = $product->name;

                // Process each ingredient in the plated dish
                foreach ($platedDish->ingredients as $ingredient) {
                    // Key includes product_id to discriminate by product (for correct sorting)
                    $key = "{$productId}_{$branchId}_{$ingredient->ingredient_name}";

                    // Initialize if not exists
                    if (!isset($groupedData[$key])) {
                        $groupedData[$key] = [
                            'ingredient_name' => $ingredient->ingredient_name,
                            'ingredient_product_code' => $this->extractProductCode($ingredient->ingredient_name),
                            'branch_id' => $branchId,
                            'branch_fantasy_name' => $branchFantasyName,
                            'product_id' => $productId,
                            'product_name' => $productName,
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
        // Sort by: product_name → ingredient_name (alphabetical) → branch_fantasy_name (alphabetical)
        $labelData = collect($groupedData)->map(function ($item) {
            return $this->calculateLabelsAndWeights($item);
        })->sortBy([
            ['product_name', 'asc'],
            ['ingredient_name', 'asc'],
            ['branch_fantasy_name', 'asc'],
        ])->values();

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

    /**
     * Get label data grouped by ReportGrouper and max_quantity_horeca for an AdvanceOrder
     *
     * This method correctly filters order lines by using the advance_order_order_lines
     * pivot table, which only contains order lines matching the OP's production areas.
     *
     * Labels are grouped by ReportGrouper (not by branch), consolidating all branches
     * that belong to the same grouper into a single label group.
     *
     * @param int $advanceOrderId AdvanceOrder ID
     * @return Collection
     */
    public function getHorecaLabelDataByAdvanceOrder(int $advanceOrderId): Collection
    {
        // Get AdvanceOrder with associated order lines (respects production area filter)
        $advanceOrder = AdvanceOrder::where('id', $advanceOrderId)
            ->with([
                'associatedOrderLines.orderLine.product.platedDish.ingredients',
                'associatedOrderLines.orderLine.order.user.branch.company',
                'associatedOrderLines.orderLine.order.user.company'
            ])
            ->first();

        if (!$advanceOrder) {
            return collect();
        }

        // Load all active groupers with their branches and companies for lookup
        $groupers = ReportGrouper::where('is_active', true)
            ->with(['branches', 'companies'])
            ->orderBy('display_order')
            ->get();

        // Collect all data grouped by grouper and ingredient
        $groupedData = [];

        foreach ($advanceOrder->associatedOrderLines as $associatedLine) {
            $orderLine = $associatedLine->orderLine;

            // Skip if order line not found
            if (!$orderLine) {
                continue;
            }

            // Skip if product doesn't have plated dish
            if (!$orderLine->product || !$orderLine->product->platedDish) {
                continue;
            }

            $platedDish = $orderLine->product->platedDish;

            // Skip if plated dish is not HORECA (is_horeca = false)
            if (!$platedDish->is_horeca) {
                continue;
            }

            $branch = $orderLine->order->user->branch ?? null;
            $company = $orderLine->order->user->company ?? null;

            // Skip if no branch found
            if (!$branch) {
                continue;
            }

            // Find grouper for this order line (by branch first, then by company)
            $grouper = $this->findGrouperForBranchAndCompany($groupers, $branch, $company);

            // Use grouper name if found, otherwise fall back to branch name
            $grouperKey = $grouper ? "grouper_{$grouper->id}" : "branch_{$branch->id}";
            $grouperName = $grouper ? $grouper->name : ($branch->fantasy_name ?? $branch->company->name ?? 'SIN SUCURSAL');

            // Get product info for discriminating labels by product
            $product = $orderLine->product;
            $productId = $product->id;
            $productName = $product->name;

            // Process each ingredient in the plated dish
            foreach ($platedDish->ingredients as $ingredient) {
                // Key includes product_id to discriminate weights by product (not consolidated)
                // This matches the Emplatado report behavior where each product shows its own weights
                $key = "{$grouperKey}_{$productId}_{$ingredient->ingredient_name}";

                // Initialize if not exists
                if (!isset($groupedData[$key])) {
                    $groupedData[$key] = [
                        'ingredient_name' => $ingredient->ingredient_name,
                        'ingredient_product_code' => $this->extractProductCode($ingredient->ingredient_name),
                        'grouper_id' => $grouper ? $grouper->id : null,
                        'grouper_name' => $grouperName,
                        // Keep branch_fantasy_name for backward compatibility
                        'branch_fantasy_name' => $grouperName,
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'measure_unit' => $ingredient->measure_unit,
                        'max_quantity_horeca' => $ingredient->max_quantity_horeca ?? 1000,
                        'shelf_life' => $ingredient->shelf_life,
                        'total_quantity_needed' => 0,
                    ];
                }

                // Add quantity: ingredient quantity per dish × order line quantity
                $quantityPerDish = (float) $ingredient->quantity;
                $dishesOrdered = (int) $orderLine->quantity;
                $groupedData[$key]['total_quantity_needed'] += $quantityPerDish * $dishesOrdered;
            }
        }

        // Calculate labels and weights for each group
        // Sort by: product_name → ingredient_name (alphabetical) → branch_fantasy_name (alphabetical)
        $labelData = collect($groupedData)->map(function ($item) {
            return $this->calculateLabelsAndWeights($item);
        })->sortBy([
            ['product_name', 'asc'],
            ['ingredient_name', 'asc'],
            ['branch_fantasy_name', 'asc'],
        ])->values();

        return $labelData;
    }

    /**
     * Find the ReportGrouper for a branch/company combination
     *
     * Priority:
     * 1. Grouper that contains the branch directly
     * 2. Grouper that contains the company
     * 3. null (no grouper found)
     *
     * @param Collection $groupers All active groupers with branches and companies loaded
     * @param mixed $branch The branch model
     * @param mixed $company The company model
     * @return ReportGrouper|null
     */
    private function findGrouperForBranchAndCompany(Collection $groupers, $branch, $company): ?ReportGrouper
    {
        // First try to find by branch
        $grouperByBranch = $groupers->first(function ($grouper) use ($branch) {
            return $grouper->branches->contains('id', $branch->id);
        });

        if ($grouperByBranch) {
            return $grouperByBranch;
        }

        // Then try to find by company
        if ($company) {
            $grouperByCompany = $groupers->first(function ($grouper) use ($company) {
                return $grouper->companies->contains('id', $company->id);
            });

            if ($grouperByCompany) {
                return $grouperByCompany;
            }
        }

        return null;
    }
}