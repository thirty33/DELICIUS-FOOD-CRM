<?php

namespace App\Repositories;

use App\Contracts\ColumnDataProviderInterface;
use App\Contracts\ConsolidadoEmplatadoRepositoryInterface;
use App\Models\AdvanceOrder;
use App\Support\ImportExport\ConsolidadoEmplatadoSchema;

class ConsolidadoEmplatadoRepository implements ConsolidadoEmplatadoRepositoryInterface
{
    public function __construct(
        private ColumnDataProviderInterface $columnDataProvider
    ) {}

    /**
     * Get consolidated plated dish report data with bag calculations.
     *
     * Returns data in TWO formats:
     * 1. Nested structure (original) - for internal processing and tests
     * 2. Flat structure - ready for Excel export (mapped to schema columns)
     *
     * @param array $advanceOrderIds Array of advance order IDs to include in the report
     * @param bool $flatFormat If true, returns flat format ready for Excel; if false, returns nested format
     * @return array Structured data with products, ingredients, columns, and bag calculations
     */
    public function getConsolidatedPlatedDishData(array $advanceOrderIds, bool $flatFormat = false): array
    {
        // Build eager load relationships including provider-specific ones
        $eagerLoads = array_merge(
            [
                'associatedOrderLines.orderLine.product.platedDish.ingredients' => function ($query) {
                    $query->where('is_optional', false)->orderBy('order_index');
                },
                'associatedOrderLines.orderLine.product.platedDish.relatedProduct',
            ],
            $this->columnDataProvider->getEagerLoadRelationships()
        );

        // Query AdvanceOrders with all necessary relationships
        $advanceOrders = AdvanceOrder::whereIn('id', $advanceOrderIds)
            ->with($eagerLoads)
            ->get();

        // NEW: Collect all related INDIVIDUAL product IDs
        $individualProductIds = $advanceOrders->flatMap(function ($advanceOrder) {
            return $advanceOrder->associatedOrderLines->pluck('orderLine.product.platedDish.related_product_id');
        })->filter()->unique()->toArray();

        // NEW: Calculate INDIVIDUAL product counts from associatedOrderLines ONLY
        // CRITICAL: Only count individual products that are explicitly assigned to the Advance Order
        // Do NOT query all order_lines from the order_ids, as that includes products not in the OP
        $individualCounts = [];
        if (!empty($individualProductIds)) {
            $processedOrderLineIdsForIndividuals = [];

            foreach ($advanceOrders as $advanceOrder) {
                foreach ($advanceOrder->associatedOrderLines as $aoOrderLine) {
                    // Deduplicate: Skip if this order_line_id was already processed
                    if (isset($processedOrderLineIdsForIndividuals[$aoOrderLine->order_line_id])) {
                        continue;
                    }
                    $processedOrderLineIdsForIndividuals[$aoOrderLine->order_line_id] = true;

                    // Skip if orderLine was deleted
                    if (!$aoOrderLine->orderLine) {
                        continue;
                    }

                    $productId = $aoOrderLine->orderLine->product_id;

                    // Only count if this is an individual product (related to a HORECA product)
                    if (in_array($productId, $individualProductIds)) {
                        if (!isset($individualCounts[$productId])) {
                            $individualCounts[$productId] = 0;
                        }
                        $individualCounts[$productId] += $aoOrderLine->orderLine->quantity;
                    }
                }
            }
        }

        // Group data by product
        $productGroups = [];

        // IMPORTANT: Deduplicate order lines that appear in multiple AdvanceOrders.
        // The same order_line_id can appear in multiple AdvanceOrders (e.g., when AO 100
        // contains all lines from AO 94 plus new ones). Without deduplication, quantities
        // would be counted multiple times, causing doubled values in the report.
        $processedOrderLineIds = [];

        foreach ($advanceOrders as $advanceOrder) {
            foreach ($advanceOrder->associatedOrderLines as $aoOrderLine) {
                // Skip if this order_line_id was already processed from another AdvanceOrder
                $orderLineId = $aoOrderLine->order_line_id;
                if (isset($processedOrderLineIds[$orderLineId])) {
                    continue;
                }
                $processedOrderLineIds[$orderLineId] = true;

                // Skip if orderLine was deleted (no longer exists in database)
                if (!$aoOrderLine->orderLine) {
                    continue;
                }

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
                // Use current orderLine quantity (not frozen quantity_covered)
                // This ensures the report reflects modifications made after OP creation
                $quantity = $aoOrderLine->orderLine->quantity;

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
                        'total_horeca' => 0,
                        'total_individual' => $individualTotal,
                        'ingredients' => [],
                    ];
                }

                // Accumulate quantities based on product type
                if ($platedDish->is_horeca) {
                    $productGroups[$productId]['total_horeca'] += $quantity;
                } else {
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
                            'columns' => [],
                        ];
                    }

                    // ONLY process columns for HORECA products
                    // INDIVIDUAL products without HORECA relation should NOT have column data
                    if ($platedDish->is_horeca) {
                        // Get column assignment from provider
                        $columnData = $this->columnDataProvider->getColumnForOrderLine($aoOrderLine);

                        if ($columnData) {
                            $columnKey = $columnData['column_key'];

                            if (!isset($productGroups[$productId]['ingredients'][$ingredientId]['columns'][$columnKey])) {
                                $productGroups[$productId]['ingredients'][$ingredientId]['columns'][$columnKey] = [
                                    'column_key' => $columnData['column_key'],
                                    'column_name' => $columnData['column_name'],
                                    'quantity' => 0,
                                ];
                            }

                            // Accumulate column quantities for this ingredient
                            $productGroups[$productId]['ingredients'][$ingredientId]['columns'][$columnKey]['quantity'] += $quantity;
                        }
                    }
                }
            }
        }

        // Calculate bag divisions and format output for each ingredient
        foreach ($productGroups as &$productGroup) {
            $totalHorecaForProduct = $productGroup['total_horeca'];
            $totalIndividualForProduct = $productGroup['total_individual'];

            foreach ($productGroup['ingredients'] as &$ingredientData) {
                // Calculate bags for each column
                $columnsWithBags = [];
                foreach ($ingredientData['columns'] as $columnData) {
                    $bagCalculation = $this->calculateBagDivisionsForClient(
                        $columnData['quantity'],
                        $ingredientData['quantity_per_pax'],
                        $ingredientData['max_quantity_horeca'],
                        $ingredientData['measure_unit']
                    );

                    $columnsWithBags[] = [
                        'column_key' => $columnData['column_key'],
                        'column_name' => $columnData['column_name'],
                        'porciones' => $bagCalculation['porciones'],
                        'gramos' => $bagCalculation['gramos'],
                        'weights' => $bagCalculation['weights'],
                        'descripcion' => $bagCalculation['descripcion'],
                    ];
                }

                // Calculate total bolsas description
                $totalBolsas = $this->calculateTotalBolsasDescription($columnsWithBags, $ingredientData['measure_unit']);

                // Replace columns array with detailed bag data
                $ingredientData['individual'] = $totalIndividualForProduct;
                $ingredientData['clientes'] = $columnsWithBags;
                $ingredientData['total_horeca'] = $totalHorecaForProduct;
                $ingredientData['total_bolsas'] = $totalBolsas;

                // Remove temporary fields
                unset($ingredientData['columns']);
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
     * This method assumes ConsolidadoEmplatadoSchema is already configured with column names.
     *
     * @param array $nestedData Nested structure from getConsolidatedPlatedDishData()
     * @return array Flat array ready for Excel export
     */
    private function transformToFlatFormat(array $nestedData): array
    {
        // Step 1: Get column names from SCHEMA (not from data)
        // This ensures the order matches the schema headers and includes all columns
        // even if some ingredients don't have data for certain columns.
        // Previously this used extractUniqueColumnNames() which caused column misalignment
        // because it extracted columns alphabetically from data and missed empty columns.
        $columnNames = array_values(ConsolidadoEmplatadoSchema::getClientColumns());

        // Step 2: Schema should already be configured by caller (Export constructor)
        // DO NOT reset schema here - it causes schema to be reset between collection() and headings()

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
                $row[$fixedPrefixKeys[0]] = $firstIngredient ? $productName : '';
                $row[$fixedPrefixKeys[1]] = $ingredientData['ingredient_name'];
                $row[$fixedPrefixKeys[2]] = $this->formatQuantityPerPax(
                    $ingredientData['quantity_per_pax'],
                    $ingredientData['measure_unit']
                );
                // Only show INDIVIDUAL value on first ingredient row (for Excel merge)
                $row[$fixedPrefixKeys[3]] = $firstIngredient ? (string) $ingredientData['individual'] : '';

                // Map client columns (dynamic)
                $clientesIndexed = collect($ingredientData['clientes'])->keyBy('column_name');

                foreach ($columnNames as $columnName) {
                    $columnKey = ConsolidadoEmplatadoSchema::getClientColumnKey($columnName);

                    if ($clientesIndexed->has($columnName)) {
                        $clientData = $clientesIndexed->get($columnName);
                        // Join description array with newlines for Excel cell
                        $row[$columnKey] = implode("\n", $clientData['descripcion']);
                    } else {
                        // Column has no orders for this ingredient
                        $row[$columnKey] = '';
                    }
                }

                // Map fixed suffix columns using schema keys
                // total_horeca, total_bolsas
                // Only show TOTAL HORECA value on first ingredient row (for Excel merge)
                $row[$fixedSuffixKeys[0]] = $firstIngredient ? (string) $ingredientData['total_horeca'] : '';
                $row[$fixedSuffixKeys[1]] = implode("\n", $ingredientData['total_bolsas']);

                $flatRows[] = $row;
                $firstIngredient = false;
            }
        }

        // Add totals row at the end
        $totalsRow = $this->calculateTotalsRow($nestedData, $columnNames);
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
     * @param array $columnNames Array of column names for column mapping
     * @return array Totals row with only INDIVIDUAL, TOTAL HORECA, and TOTAL BOLSAS filled
     */
    private function calculateTotalsRow(array $nestedData, array $columnNames): array
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
        $totalsRow[$fixedPrefixKeys[0]] = '';
        $totalsRow[$fixedPrefixKeys[1]] = '';
        $totalsRow[$fixedPrefixKeys[2]] = '';

        // INDIVIDUAL column with total
        $totalsRow[$fixedPrefixKeys[3]] = "TOTAL {$totalIndividual}";

        // Empty client columns (dynamic)
        foreach ($columnNames as $columnName) {
            $columnKey = ConsolidadoEmplatadoSchema::getClientColumnKey($columnName);
            $totalsRow[$columnKey] = '';
        }

        // TOTAL HORECA column with total
        $totalsRow[$fixedSuffixKeys[0]] = "TOTAL {$totalHoreca}";

        // TOTAL BOLSAS column with combined total
        $totalsRow[$fixedSuffixKeys[1]] = "TOTAL PLATOS {$totalPlatos}";

        return $totalsRow;
    }

    /**
     * Get unique column names from advance order IDs (PUBLIC METHOD)
     *
     * This method is used to extract column names BEFORE creating the export,
     * so they can be passed to the export constructor and survive queue serialization.
     *
     * @param array $advanceOrderIds Array of advance order IDs
     * @return array Array of unique column names, sorted alphabetically
     */
    public function getColumnNamesFromAdvanceOrders(array $advanceOrderIds): array
    {
        // Build eager load relationships including provider-specific ones
        $eagerLoads = array_merge(
            ['associatedOrderLines.orderLine'],
            $this->columnDataProvider->getEagerLoadRelationships()
        );

        $advanceOrders = AdvanceOrder::whereIn('id', $advanceOrderIds)
            ->with($eagerLoads)
            ->get();

        return $this->columnDataProvider->getColumnNames($advanceOrders);
    }

    /**
     * Get unique branch names from advance order IDs.
     *
     * @deprecated Use getColumnNamesFromAdvanceOrders() instead
     * @param array $advanceOrderIds Array of advance order IDs
     * @return array Array of unique branch fantasy names, sorted alphabetically
     */
    public function getBranchNamesFromAdvanceOrders(array $advanceOrderIds): array
    {
        return $this->getColumnNamesFromAdvanceOrders($advanceOrderIds);
    }

    /**
     * Extract all unique column names from nested data structure.
     *
     * Scans through all products and ingredients to collect unique column names
     * that appear in the data. These will be used to configure schema columns.
     *
     * @param array $nestedData Nested structure from getConsolidatedPlatedDishData()
     * @return array Array of unique column names, sorted alphabetically
     */
    private function extractUniqueColumnNames(array $nestedData): array
    {
        $columnNames = [];

        foreach ($nestedData as $productData) {
            foreach ($productData['ingredients'] as $ingredientData) {
                foreach ($ingredientData['clientes'] as $clientData) {
                    $columnNames[$clientData['column_name']] = true;
                }
            }
        }

        $uniqueColumns = array_keys($columnNames);
        sort($uniqueColumns);

        return $uniqueColumns;
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
            // Get the plural/singular unit name based on the weight quantity
            $unitName = \App\Enums\MeasureUnit::getPluralNameFromString($measureUnit, $weight);

            if ($count === 1) {
                $parts[] = "1 BOLSA DE {$weight} {$unitName}";
            } else {
                $parts[] = "{$count} BOLSAS DE {$weight} {$unitName}";
            }
        }

        return $parts;
    }

    /**
     * Calculate consolidated total bags description for an ingredient.
     *
     * @param array $columns Array of column data with weights
     * @param string $measureUnit Measure unit
     * @return array Array of formatted total bags descriptions
     */
    private function calculateTotalBolsasDescription(array $columns, string $measureUnit): array
    {
        $allWeights = [];

        foreach ($columns as $columnData) {
            foreach ($columnData['weights'] as $weight) {
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