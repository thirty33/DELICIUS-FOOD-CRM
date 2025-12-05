<?php

namespace App\Repositories;

use App\Contracts\ConsolidadoEmplatadoRepositoryInterface;
use App\Models\AdvanceOrder;

class ConsolidadoEmplatadoRepository implements ConsolidadoEmplatadoRepositoryInterface
{
    /**
     * Get consolidated plated dish report data with bag calculations.
     *
     * @param array $advanceOrderIds Array of advance order IDs to include in the report
     * @return array Structured data with products, ingredients, branches, and bag calculations
     */
    public function getConsolidatedPlatedDishData(array $advanceOrderIds): array
    {
        // Query AdvanceOrders with all necessary relationships
        $advanceOrders = AdvanceOrder::whereIn('id', $advanceOrderIds)
            ->with([
                'associatedOrderLines.orderLine.product.platedDish.ingredients' => function ($query) {
                    $query->where('is_optional', false)->orderBy('order_index');
                },
                'associatedOrderLines.order.user.branch',
            ])
            ->get();

        // Group data by product
        $productGroups = [];

        foreach ($advanceOrders as $advanceOrder) {
            foreach ($advanceOrder->associatedOrderLines as $aoOrderLine) {
                $product = $aoOrderLine->orderLine->product;
                $platedDish = $product->platedDish;

                // Skip products without plated dish
                if (!$platedDish) {
                    continue;
                }

                $productId = $product->id;

                // Initialize product group if not exists
                if (!isset($productGroups[$productId])) {
                    $productGroups[$productId] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_code' => $product->code,
                        'ingredients' => [],
                    ];
                }

                // Process each ingredient
                foreach ($platedDish->ingredients as $ingredient) {
                    $ingredientId = $ingredient->id;

                    // Initialize ingredient group if not exists
                    if (!isset($productGroups[$productId]['ingredients'][$ingredientId])) {
                        $productGroups[$productId]['ingredients'][$ingredientId] = [
                            'ingredient_id' => $ingredient->id,
                            'ingredient_name' => $ingredient->ingredient_name,
                            'measure_unit' => $ingredient->measure_unit,
                            'quantity_per_pax' => $ingredient->quantity,
                            'max_quantity_horeca' => $ingredient->max_quantity_horeca,
                            'branches' => [],
                            'total_horeca' => 0,
                        ];
                    }

                    // Add branch data
                    $branch = $aoOrderLine->order->user->branch;
                    $branchId = $branch->id;
                    $quantity = $aoOrderLine->quantity_covered;

                    if (!isset($productGroups[$productId]['ingredients'][$ingredientId]['branches'][$branchId])) {
                        $productGroups[$productId]['ingredients'][$ingredientId]['branches'][$branchId] = [
                            'branch_id' => $branch->id,
                            'branch_name' => $branch->fantasy_name,
                            'quantity' => 0,
                        ];
                    }

                    // Accumulate quantities
                    $productGroups[$productId]['ingredients'][$ingredientId]['branches'][$branchId]['quantity'] += $quantity;
                    $productGroups[$productId]['ingredients'][$ingredientId]['total_horeca'] += $quantity;
                }
            }
        }

        // Calculate bag divisions and format output for each ingredient
        foreach ($productGroups as &$productGroup) {
            foreach ($productGroup['ingredients'] as &$ingredientData) {
                // Calculate bags for each branch
                $branchesWithBags = [];
                foreach ($ingredientData['branches'] as $branchData) {
                    $bagCalculation = $this->calculateBagDivisionsForClient(
                        $branchData['quantity'],
                        $ingredientData['quantity_per_pax'],
                        $ingredientData['max_quantity_horeca'],
                        $ingredientData['measure_unit']
                    );

                    $branchesWithBags[] = [
                        'branch_id' => $branchData['branch_id'],
                        'branch_name' => $branchData['branch_name'],
                        'porciones' => $bagCalculation['porciones'],
                        'gramos' => $bagCalculation['gramos'],
                        'weights' => $bagCalculation['weights'],
                        'descripcion' => $bagCalculation['descripcion'],
                    ];
                }

                // Calculate total bolsas description
                $totalBolsas = $this->calculateTotalBolsasDescription($branchesWithBags, $ingredientData['measure_unit']);

                // Replace branches array with detailed bag data
                $ingredientData['individual'] = 0; // Always 0 for HORECA
                $ingredientData['clientes'] = $branchesWithBags;
                $ingredientData['total_bolsas'] = $totalBolsas;

                // Remove temporary fields
                unset($ingredientData['branches']);
                unset($ingredientData['ingredient_id']);
                unset($ingredientData['max_quantity_horeca']);
            }

            // Convert ingredients associative array to indexed array
            $productGroup['ingredients'] = array_values($productGroup['ingredients']);
        }

        return array_values($productGroups);
    }

    /**
     * Calculate bag divisions for a client based on portions, quantity per PAX, and max quantity per bag.
     *
     * @param int $portions Number of portions
     * @param float $quantityPerPax Quantity per portion (e.g., 200g)
     * @param float $maxQuantityPerBag Maximum quantity per bag (e.g., 1000g)
     * @param string $measureUnit Measure unit (GR, ML, UND)
     * @return array Bag calculation data with weights and description
     */
    private function calculateBagDivisionsForClient(int $portions, float $quantityPerPax, float $maxQuantityPerBag, string $measureUnit): array
    {
        $totalGrams = $portions * $quantityPerPax;
        $weights = [];

        $remaining = $totalGrams;
        while ($remaining > 0) {
            if ($remaining >= $maxQuantityPerBag) {
                $weights[] = $maxQuantityPerBag;
                $remaining -= $maxQuantityPerBag;
            } else {
                $weights[] = $remaining;
                $remaining = 0;
            }
        }

        $description = $this->formatBagDescription($weights, $measureUnit);

        return [
            'porciones' => $portions,
            'gramos' => $totalGrams,
            'weights' => $weights,
            'descripcion' => $description,
        ];
    }

    /**
     * Format bag description from weights array.
     *
     * @param array $weights Array of bag weights
     * @param string $measureUnit Measure unit
     * @return array Array of formatted bag descriptions
     */
    private function formatBagDescription(array $weights, string $measureUnit): array
    {
        $grouped = [];
        foreach ($weights as $weight) {
            if (!isset($grouped[$weight])) {
                $grouped[$weight] = 0;
            }
            $grouped[$weight]++;
        }

        // Sort by weight descending
        krsort($grouped);

        $parts = [];
        foreach ($grouped as $weight => $count) {
            if ($count === 1) {
                $parts[] = "1 BOLSA DE {$weight} GRAMOS";
            } else {
                $parts[] = "{$count} BOLSAS DE {$weight} GRAMOS";
            }
        }

        return $parts; // Return array instead of string
    }

    /**
     * Calculate consolidated total bags description for an ingredient.
     *
     * @param array $branches Array of branch data with weights
     * @param string $measureUnit Measure unit
     * @return array Array of formatted total bags descriptions
     */
    private function calculateTotalBolsasDescription(array $branches, string $measureUnit): array
    {
        $allWeights = [];

        foreach ($branches as $branchData) {
            foreach ($branchData['weights'] as $weight) {
                $allWeights[] = $weight;
            }
        }

        return $this->formatBagDescription($allWeights, $measureUnit);
    }
}