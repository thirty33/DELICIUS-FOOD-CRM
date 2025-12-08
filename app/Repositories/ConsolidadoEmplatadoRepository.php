<?php

namespace App\Repositories;

use App\Contracts\ConsolidadoEmplatadoRepositoryInterface;
use App\Models\AdvanceOrder;
use App\Support\ImportExport\ConsolidadoEmplatadoSchema;

class ConsolidadoEmplatadoRepository implements ConsolidadoEmplatadoRepositoryInterface
{
    /**
     * Get consolidated plated dish report data with bag calculations.
     *
     * Returns data in TWO formats:
     * 1. Nested structure (original) - for internal processing and tests
     * 2. Flat structure - ready for Excel export (mapped to schema columns)
     *
     * @param array $advanceOrderIds Array of advance order IDs to include in the report
     * @param bool $flatFormat If true, returns flat format ready for Excel; if false, returns nested format
     * @return array Structured data with products, ingredients, branches, and bag calculations
     */
    public function getConsolidatedPlatedDishData(array $advanceOrderIds, bool $flatFormat = false): array
    {
        // Query AdvanceOrders with all necessary relationships
        $advanceOrders = AdvanceOrder::whereIn('id', $advanceOrderIds)
            ->with([
                'associatedOrderLines.orderLine.product.platedDish.ingredients' => function ($query) {
                    $query->where('is_optional', false)->orderBy('order_index');
                },
                'associatedOrderLines.orderLine.product.platedDish.relatedProduct', // NEW: eager load related INDIVIDUAL product
                'associatedOrderLines.order.user.branch',
            ])
            ->get();

        // NEW: Collect all related INDIVIDUAL product IDs
        $individualProductIds = $advanceOrders->flatMap(function ($advanceOrder) {
            return $advanceOrder->associatedOrderLines->pluck('orderLine.product.platedDish.related_product_id');
        })->filter()->unique()->toArray();

        // NEW: Query INDIVIDUAL product sales (from ALL orders, not just advance orders)
        $individualCounts = [];
        if (!empty($individualProductIds)) {
            $orderIds = $advanceOrders->flatMap(function ($advanceOrder) {
                return $advanceOrder->associatedOrderLines->pluck('orderLine.order_id');
            })->unique()->toArray();

            $individualCounts = \App\Models\OrderLine::whereIn('order_id', $orderIds)
                ->whereIn('product_id', $individualProductIds)
                ->selectRaw('product_id, SUM(quantity) as total_quantity')
                ->groupBy('product_id')
                ->get()
                ->pluck('total_quantity', 'product_id')
                ->toArray();
        }

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

                // Skip products that are related to another product
                // (they will be shown combined with their related product)
                if (in_array($product->id, $individualProductIds)) {
                    continue;
                }

                $productId = $product->id;
                $quantity = $aoOrderLine->quantity_covered;

                // Initialize product group if not exists
                if (!isset($productGroups[$productId])) {
                    // Get related INDIVIDUAL product and its sales count
                    $relatedProduct = $platedDish->relatedProduct;
                    $individualTotal = 0;

                    if ($relatedProduct) {
                        $individualTotal = $individualCounts[$relatedProduct->id] ?? 0;
                    }

                    $productGroups[$productId] = [
                        'product_id' => $product->id,
                        'product_name' => $this->buildProductName($product, $relatedProduct),
                        'product_code' => $product->code,
                        'related_product' => $relatedProduct,
                        'is_horeca' => $platedDish->is_horeca,
                        'total_horeca' => 0, // Total HORECA plated dishes ordered
                        'total_individual' => $individualTotal, // Total INDIVIDUAL products sold (for HORECA with related product)
                        'ingredients' => [],
                    ];
                }

                // Accumulate quantities based on product type
                if ($platedDish->is_horeca) {
                    // HORECA product: accumulate to total_horeca
                    $productGroups[$productId]['total_horeca'] += $quantity;
                } else {
                    // INDIVIDUAL product: accumulate to total_individual
                    $productGroups[$productId]['total_individual'] += $quantity;
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
                        ];
                    }

                    // ONLY process branches for HORECA products
                    // INDIVIDUAL products without HORECA relation should NOT have branch data
                    if ($platedDish->is_horeca) {
                        // Add branch data
                        $branch = $aoOrderLine->order->user->branch;
                        $branchId = $branch->id;

                        if (!isset($productGroups[$productId]['ingredients'][$ingredientId]['branches'][$branchId])) {
                            $productGroups[$productId]['ingredients'][$ingredientId]['branches'][$branchId] = [
                                'branch_id' => $branch->id,
                                'branch_name' => $branch->fantasy_name,
                                'quantity' => 0,
                            ];
                        }

                        // Accumulate branch quantities for this ingredient
                        $productGroups[$productId]['ingredients'][$ingredientId]['branches'][$branchId]['quantity'] += $quantity;
                    }
                }
            }
        }

        // Calculate bag divisions and format output for each ingredient
        foreach ($productGroups as &$productGroup) {
            $totalHorecaForProduct = $productGroup['total_horeca']; // Get total from product level
            $totalIndividualForProduct = $productGroup['total_individual']; // Get total INDIVIDUAL from product level

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
                $ingredientData['individual'] = $totalIndividualForProduct; // Total INDIVIDUAL products sold
                $ingredientData['clientes'] = $branchesWithBags;
                $ingredientData['total_horeca'] = $totalHorecaForProduct; // Assign product-level total to ingredient
                $ingredientData['total_bolsas'] = $totalBolsas;

                // Remove temporary fields
                unset($ingredientData['branches']);
                unset($ingredientData['ingredient_id']);
                unset($ingredientData['max_quantity_horeca']);
            }

            // Convert ingredients associative array to indexed array
            $productGroup['ingredients'] = array_values($productGroup['ingredients']);
        }

        $nestedData = array_values($productGroups);

        // If flat format requested, transform to schema-compatible structure
        if ($flatFormat) {
            return $this->transformToFlatFormat($nestedData);
        }

        return $nestedData;
    }

    /**
     * Transform nested data structure to flat format ready for Excel export.
     *
     * Converts nested structure (product -> ingredients -> clients) to flat array
     * where each row represents one ingredient with all client data in columns.
     *
     * IMPORTANT: The schema should be configured BEFORE calling this method.
     * This method assumes ConsolidadoEmplatadoSchema is already configured with branch columns.
     *
     * @param array $nestedData Nested structure from getConsolidatedPlatedDishData()
     * @return array Flat array ready for Excel export
     */
    private function transformToFlatFormat(array $nestedData): array
    {
        // Step 1: Extract all unique branch names from data
        $branchNames = $this->extractUniqueBranchNames($nestedData);

        // Step 2: Schema should already be configured by caller (Export constructor)
        // DO NOT reset schema here - it causes schema to be reset between collection() and headings()
        // ConsolidadoEmplatadoSchema::setClientColumns($branchNames);

        // Step 3: Get schema keys for mapping
        $fixedPrefixKeys = array_keys(ConsolidadoEmplatadoSchema::getFixedPrefixColumns());
        $fixedSuffixKeys = array_keys(ConsolidadoEmplatadoSchema::getFixedSuffixColumns());

        // Step 4: Transform data to flat format
        $flatRows = [];

        foreach ($nestedData as $productData) {
            $productName = $productData['product_name'];
            $firstIngredient = true;

            foreach ($productData['ingredients'] as $ingredientData) {
                $row = [];

                // Map fixed prefix columns using schema keys
                // plato, ingrediente, cantidad_x_pax, individual
                $row[$fixedPrefixKeys[0]] = $firstIngredient ? $productName : ''; // plato
                $row[$fixedPrefixKeys[1]] = $ingredientData['ingredient_name']; // ingrediente
                $row[$fixedPrefixKeys[2]] = $this->formatQuantityPerPax( // cantidad_x_pax
                    $ingredientData['quantity_per_pax'],
                    $ingredientData['measure_unit']
                );
                $row[$fixedPrefixKeys[3]] = (string) $ingredientData['individual']; // individual products sold

                // Map client columns (dynamic)
                $clientesIndexed = collect($ingredientData['clientes'])->keyBy('branch_name');

                foreach ($branchNames as $branchName) {
                    $columnKey = ConsolidadoEmplatadoSchema::getClientColumnKey($branchName);

                    if ($clientesIndexed->has($branchName)) {
                        $clientData = $clientesIndexed->get($branchName);
                        // Join description array with newlines for Excel cell
                        $row[$columnKey] = implode("\n", $clientData['descripcion']);
                    } else {
                        // Branch has no orders for this ingredient
                        $row[$columnKey] = '';
                    }
                }

                // Map fixed suffix columns using schema keys
                // total_horeca, total_bolsas
                $row[$fixedSuffixKeys[0]] = (string) $ingredientData['total_horeca']; // total_horeca
                $row[$fixedSuffixKeys[1]] = implode("\n", $ingredientData['total_bolsas']); // total_bolsas

                $flatRows[] = $row;
                $firstIngredient = false;
            }
        }

        // Add totals row at the end
        $totalsRow = $this->calculateTotalsRow($nestedData, $branchNames);
        $flatRows[] = $totalsRow;

        return $flatRows;
    }

    /**
     * Calculate totals row for the report.
     *
     * Sums up:
     * - INDIVIDUAL column: Total individual products sold
     * - TOTAL HORECA column: Total HORECA products
     * - TOTAL BOLSAS column: Combined total (INDIVIDUAL + TOTAL HORECA)
     *
     * @param array $nestedData Nested structure from getConsolidatedPlatedDishData()
     * @param array $branchNames Array of branch names for column mapping
     * @return array Totals row with only INDIVIDUAL, TOTAL HORECA, and TOTAL BOLSAS filled
     */
    private function calculateTotalsRow(array $nestedData, array $branchNames): array
    {
        $fixedPrefixKeys = array_keys(ConsolidadoEmplatadoSchema::getFixedPrefixColumns());
        $fixedSuffixKeys = array_keys(ConsolidadoEmplatadoSchema::getFixedSuffixColumns());

        $totalIndividual = 0;
        $totalHoreca = 0;

        // Calculate totals from nested data
        foreach ($nestedData as $productData) {
            $totalIndividual += $productData['total_individual'];
            $totalHoreca += $productData['total_horeca'];
        }

        $totalPlatos = $totalIndividual + $totalHoreca;

        // Build totals row
        $totalsRow = [];

        // Empty columns: PLATO, INGREDIENTE, CANTIDAD X PAX
        $totalsRow[$fixedPrefixKeys[0]] = ''; // plato
        $totalsRow[$fixedPrefixKeys[1]] = ''; // ingrediente
        $totalsRow[$fixedPrefixKeys[2]] = ''; // cantidad_x_pax

        // INDIVIDUAL column with total
        $totalsRow[$fixedPrefixKeys[3]] = "TOTAL {$totalIndividual}"; // individual

        // Empty client columns (dynamic)
        foreach ($branchNames as $branchName) {
            $columnKey = ConsolidadoEmplatadoSchema::getClientColumnKey($branchName);
            $totalsRow[$columnKey] = '';
        }

        // TOTAL HORECA column with total
        $totalsRow[$fixedSuffixKeys[0]] = "TOTAL {$totalHoreca}"; // total_horeca

        // TOTAL BOLSAS column with combined total
        $totalsRow[$fixedSuffixKeys[1]] = "TOTAL PLATOS {$totalPlatos}"; // total_bolsas

        return $totalsRow;
    }

    /**
     * Get unique branch names from advance order IDs (PUBLIC METHOD)
     *
     * This method is used to extract branch names BEFORE creating the export,
     * so they can be passed to the export constructor and survive queue serialization.
     *
     * @param array $advanceOrderIds Array of advance order IDs
     * @return array Array of unique branch fantasy names, sorted alphabetically
     */
    public function getBranchNamesFromAdvanceOrders(array $advanceOrderIds): array
    {
        // Get nested data (not flat format)
        $nestedData = $this->getConsolidatedPlatedDishData($advanceOrderIds, false);

        // Extract unique branch names
        return $this->extractUniqueBranchNames($nestedData);
    }

    /**
     * Extract all unique branch names from nested data structure.
     *
     * Scans through all products and ingredients to collect unique branch names
     * that appear in the data. These will be used to configure schema columns.
     *
     * @param array $nestedData Nested structure from getConsolidatedPlatedDishData()
     * @return array Array of unique branch fantasy names, sorted alphabetically
     */
    private function extractUniqueBranchNames(array $nestedData): array
    {
        $branchNames = [];

        foreach ($nestedData as $productData) {
            foreach ($productData['ingredients'] as $ingredientData) {
                foreach ($ingredientData['clientes'] as $clientData) {
                    $branchNames[$clientData['branch_name']] = true;
                }
            }
        }

        $uniqueBranches = array_keys($branchNames);
        sort($uniqueBranches); // Sort alphabetically for consistent column order

        return $uniqueBranches;
    }

    /**
     * Format quantity per PAX with measure unit.
     *
     * @param float $quantity Quantity value
     * @param string $measureUnit Measure unit (GR, ML, UND)
     * @return string Formatted string (e.g., "200 GRAMOS", "1 UNIDAD")
     */
    private function formatQuantityPerPax(float $quantity, string $measureUnit): string
    {
        $unitMap = [
            'GR' => 'GRAMOS',
            'ML' => 'ML',
            'UND' => $quantity == 1 ? 'UNIDAD' : 'UNIDADES',
            'UNIDAD' => $quantity == 1 ? 'UNIDAD' : 'UNIDADES',
        ];

        $unitDisplay = $unitMap[strtoupper($measureUnit)] ?? strtoupper($measureUnit);

        // Format quantity without decimals if it's a whole number
        $quantityDisplay = (int)$quantity == $quantity ? (int)$quantity : $quantity;

        return "{$quantityDisplay} {$unitDisplay}";
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

    /**
     * Build product name combining HORECA and INDIVIDUAL products.
     *
     * @param \App\Models\Product $horecaProduct The HORECA product
     * @param \App\Models\Product|null $individualProduct The related INDIVIDUAL product (if exists)
     * @return string Combined product name or just HORECA name
     */
    private function buildProductName(\App\Models\Product $horecaProduct, ?\App\Models\Product $individualProduct): string
    {
        if ($individualProduct) {
            return "{$horecaProduct->name}\n{$individualProduct->name}";
        }

        return $horecaProduct->name;
    }
}