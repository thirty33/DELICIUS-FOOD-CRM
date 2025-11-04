<?php

namespace App\Repositories;

use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdvanceOrderRepository
{
    /**
     * Get previous advance orders with overlapping dispatch date ranges
     *
     * This method finds previous production orders whose dispatch date ranges
     * overlap with the current order's range. It no longer requires identical
     * preparation dates or dispatch ranges.
     *
     * Overlapping scenarios:
     * 1. Previous order's initial date is within current range
     * 2. Previous order's final date is within current range
     * 3. Previous order's range completely contains current range
     *
     * @param AdvanceOrder $currentAdvanceOrder
     * @return Collection Collection of AdvanceOrder models
     */
    public function getPreviousAdvanceOrdersWithSameDates(AdvanceOrder $currentAdvanceOrder): Collection
    {
        return AdvanceOrder::where('id', '<', $currentAdvanceOrder->id)
            ->where('status', '!=', 'CANCELLED') // Exclude cancelled orders - they never produced anything
            ->where(function($query) use ($currentAdvanceOrder) {
                // Scenario 1: Previous order's initial_dispatch_date falls within current range
                $query->whereBetween('initial_dispatch_date', [
                    $currentAdvanceOrder->initial_dispatch_date,
                    $currentAdvanceOrder->final_dispatch_date
                ])
                // Scenario 2: Previous order's final_dispatch_date falls within current range
                ->orWhereBetween('final_dispatch_date', [
                    $currentAdvanceOrder->initial_dispatch_date,
                    $currentAdvanceOrder->final_dispatch_date
                ])
                // Scenario 3: Previous order's range completely contains current range
                ->orWhere(function($q) use ($currentAdvanceOrder) {
                    $q->where('initial_dispatch_date', '<=', $currentAdvanceOrder->initial_dispatch_date)
                      ->where('final_dispatch_date', '>=', $currentAdvanceOrder->final_dispatch_date);
                });
            })
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Get max ordered quantity for a product from previous advance orders
     * considering only orders in overlapping dispatch dates
     *
     * IMPORTANT: This method now uses pivot tables to determine EXACTLY what
     * orders and quantities were covered by previous production orders.
     *
     * The pivot tables (advance_order_order_lines) store a snapshot of which
     * order lines were associated with each production order at the moment
     * it was created. This solves the "late orders" problem where orders
     * arriving after a previous OP was created should count as "new" for the
     * current OP.
     *
     * Example:
     * - OP #2 [B-F] created at 10:00, covers 10 units in [D-E] dates
     * - OP #3 [D-E] created at 12:00, current count shows 15 units
     * - Overlap: [D-E]
     * - Query pivot: returns 10 (only orders that existed when OP #2 was created)
     * - ordered_quantity_new = 15 - 10 = 5 ✅ (the 5 late orders)
     *
     * @param int $productId
     * @param Collection $previousAdvanceOrders Collection of AdvanceOrder models
     * @param AdvanceOrder $currentAdvanceOrder The current production order
     * @return int Maximum quantity covered by previous OPs in overlapping dates
     */
    public function getMaxOrderedQuantityForProduct(
        int $productId,
        Collection $previousAdvanceOrders,
        AdvanceOrder $currentAdvanceOrder
    ): int {
        if ($previousAdvanceOrders->isEmpty()) {
            return 0;
        }

        $maxQuantityInOverlap = 0;

        foreach ($previousAdvanceOrders as $prevOrder) {
            // Calculate overlapping date range
            $overlapStart = max(
                $prevOrder->initial_dispatch_date->format('Y-m-d'),
                $currentAdvanceOrder->initial_dispatch_date->format('Y-m-d')
            );
            $overlapEnd = min(
                $prevOrder->final_dispatch_date->format('Y-m-d'),
                $currentAdvanceOrder->final_dispatch_date->format('Y-m-d')
            );

            // Only process if there's actual overlap
            if ($overlapStart <= $overlapEnd) {
                // Get what the previous order actually covered for this product
                $prevOrderProduct = AdvanceOrderProduct::where('advance_order_id', $prevOrder->id)
                    ->where('product_id', $productId)
                    ->first();

                if (!$prevOrderProduct) {
                    continue;
                }

                // Query pivot tables to get EXACT quantities covered by prevOrder
                // in the overlap dates. This uses the historical snapshot from when
                // prevOrder was created.
                $quantityCoveredInOverlap = DB::table('advance_order_order_lines as aool')
                    ->join('order_lines as ol', 'aool.order_line_id', '=', 'ol.id')
                    ->join('orders as o', 'ol.order_id', '=', 'o.id')
                    ->join('advance_order_orders as aoo', function($join) use ($prevOrder) {
                        $join->on('aoo.order_id', '=', 'o.id')
                             ->where('aoo.advance_order_id', '=', $prevOrder->id);
                    })
                    ->where('aool.advance_order_id', $prevOrder->id)
                    ->where('aool.product_id', $productId)
                    ->whereBetween('o.dispatch_date', [$overlapStart, $overlapEnd])
                    ->sum('aool.quantity_covered');

                // The pivot table already contains the exact historical data
                // No need for min() comparison - this IS what prevOrder covered
                $maxQuantityInOverlap = max($maxQuantityInOverlap, $quantityCoveredInOverlap);
            }
        }

        return $maxQuantityInOverlap;
    }

    /**
     * Get all advance orders with same dates (preparation and dispatch range)
     *
     * @param AdvanceOrder $currentAdvanceOrder
     * @return Collection Collection of AdvanceOrder models
     */
    public function getAdvanceOrdersWithSameDates(AdvanceOrder $currentAdvanceOrder): Collection
    {
        return AdvanceOrder::where('preparation_datetime', $currentAdvanceOrder->preparation_datetime)
            ->where('initial_dispatch_date', $currentAdvanceOrder->initial_dispatch_date)
            ->where('final_dispatch_date', $currentAdvanceOrder->final_dispatch_date)
            ->where('id', '!=', $currentAdvanceOrder->id)
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Check if advance order can be cancelled
     * Can cancel if: no other EXECUTED orders with same dates OR this is the last created EXECUTED order
     *
     * @param AdvanceOrder $advanceOrder
     * @return bool
     */
    public function canCancelAdvanceOrder(AdvanceOrder $advanceOrder): bool
    {
        $ordersWithSameDates = $this->getAdvanceOrdersWithSameDates($advanceOrder);

        // Filter only EXECUTED orders
        $executedOrders = $ordersWithSameDates->filter(function ($order) {
            return $order->status === \App\Enums\AdvanceOrderStatus::EXECUTED;
        });

        // If no other EXECUTED orders with same dates, can cancel
        if ($executedOrders->isEmpty()) {
            return true;
        }

        // Check if this is the last created EXECUTED order (highest ID)
        $maxId = $executedOrders->max('id');
        return $advanceOrder->id > $maxId;
    }

    /**
     * Check if advance order can be deleted
     * Can only delete if status is CANCELLED
     *
     * @param AdvanceOrder $advanceOrder
     * @return bool
     */
    public function canDeleteAdvanceOrder(AdvanceOrder $advanceOrder): bool
    {
        return $advanceOrder->status === \App\Enums\AdvanceOrderStatus::CANCELLED;
    }

    /**
     * Delete advance order and all its related pivot records
     * This should only be called for CANCELLED orders
     *
     * @param AdvanceOrder $advanceOrder
     * @return bool
     * @throws \Exception if trying to delete non-cancelled order
     */
    public function deleteAdvanceOrder(AdvanceOrder $advanceOrder): bool
    {
        if (!$this->canDeleteAdvanceOrder($advanceOrder)) {
            throw new \Exception('Cannot delete advance order. Only CANCELLED orders can be deleted.');
        }

        return DB::transaction(function () use ($advanceOrder) {
            // Delete pivot records from advance_order_order_lines
            DB::table('advance_order_order_lines')
                ->where('advance_order_id', $advanceOrder->id)
                ->delete();

            // Delete pivot records from advance_order_orders
            DB::table('advance_order_orders')
                ->where('advance_order_id', $advanceOrder->id)
                ->delete();

            // Delete advance order products
            $advanceOrder->advanceOrderProducts()->delete();

            // Delete the advance order itself
            return $advanceOrder->delete();
        });
    }

    /**
     * Get all products for an advance order with category information
     * Ordered by category name and product code
     *
     * @param int $advanceOrderId
     * @return Collection
     */
    public function getAdvanceOrderProductsForReport(int $advanceOrderId): Collection
    {
        return DB::table('advance_order_products')
            ->join('products', 'advance_order_products.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('advance_order_products.advance_order_id', $advanceOrderId)
            ->select(
                'products.id as product_id',
                'products.code as product_code',
                'products.name as product_name',
                'categories.id as category_id',
                'categories.name as category_name',
                'advance_order_products.ordered_quantity',
                'advance_order_products.quantity as manual_quantity',
                'advance_order_products.total_to_produce'
            )
            ->orderBy('categories.name')
            ->orderBy('products.code')
            ->get();
    }

    /**
     * Get products for multiple advance orders grouped and ordered
     * Returns products without duplicates with data from each OP
     *
     * @param array $advanceOrderIds
     * @return Collection
     */
    public function getAdvanceOrderProductsForMultipleReport(array $advanceOrderIds): Collection
    {
        // First, get all advance orders ordered by created_at
        $advanceOrders = AdvanceOrder::whereIn('id', $advanceOrderIds)
            ->orderBy('created_at', 'asc')
            ->get()
            ->keyBy('id');

        // Get all products from all OPs
        $allProducts = DB::table('advance_order_products')
            ->join('products', 'advance_order_products.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereIn('advance_order_products.advance_order_id', $advanceOrderIds)
            ->select(
                'advance_order_products.advance_order_id',
                'products.id as product_id',
                'products.code as product_code',
                'products.name as product_name',
                'categories.id as category_id',
                'categories.name as category_name',
                'advance_order_products.ordered_quantity',
                'advance_order_products.quantity as manual_quantity',
                'advance_order_products.total_to_produce'
            )
            ->get();

        // Group by product_id
        $groupedProducts = $allProducts->groupBy('product_id');

        // Build result with grouped data
        $result = $groupedProducts->map(function ($productGroup) use ($advanceOrders) {
            $firstProduct = $productGroup->first();

            // Calculate total ordered_quantity (sum from all OPs for this product)
            $totalOrderedQuantity = $productGroup->sum('ordered_quantity');

            // Build data array
            $productData = [
                'product_id' => $firstProduct->product_id,
                'product_code' => $firstProduct->product_code,
                'product_name' => $firstProduct->product_name,
                'category_id' => $firstProduct->category_id,
                'category_name' => $firstProduct->category_name ?? 'Sin Categoría',
                'total_ordered_quantity' => $totalOrderedQuantity,
                'ops' => []
            ];

            // Add data for each OP
            foreach ($productGroup as $product) {
                $opOrder = array_search($product->advance_order_id, array_keys($advanceOrders->toArray()));

                $productData['ops'][$product->advance_order_id] = [
                    'op_order' => $opOrder + 1, // 1-indexed
                    'manual_quantity' => $product->manual_quantity,
                    'total_to_produce' => $product->total_to_produce,
                ];
            }

            return $productData;
        });

        // Sort by category and product code
        return $result->sortBy([
            ['category_name', 'asc'],
            ['product_code', 'asc']
        ])->values();
    }

    /**
     * Get products grouped by production areas for multiple advance orders report
     *
     * @param array $advanceOrderIds
     * @return Collection Grouped by production_area_name
     */
    public function getAdvanceOrderProductsGroupedByProductionArea(array $advanceOrderIds): Collection
    {
        // First, get all advance orders ordered by created_at
        $advanceOrders = AdvanceOrder::whereIn('id', $advanceOrderIds)
            ->orderBy('created_at', 'asc')
            ->get()
            ->keyBy('id');

        // Get all products from all OPs with their production areas and warehouse stock (WITHOUT order_lines JOIN)
        $allProducts = DB::table('advance_order_products')
            ->join('products', 'advance_order_products.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('production_area_product', 'products.id', '=', 'production_area_product.product_id')
            ->leftJoin('production_areas', 'production_area_product.production_area_id', '=', 'production_areas.id')
            ->leftJoin('warehouse_product', function($join) {
                $join->on('products.id', '=', 'warehouse_product.product_id')
                     ->whereExists(function($query) {
                         $query->select(DB::raw(1))
                               ->from('warehouses')
                               ->whereColumn('warehouses.id', 'warehouse_product.warehouse_id')
                               ->where('warehouses.is_default', true);
                     });
            })
            ->whereIn('advance_order_products.advance_order_id', $advanceOrderIds)
            ->select(
                'advance_order_products.advance_order_id',
                'products.id as product_id',
                'products.code as product_code',
                'products.name as product_name',
                'categories.id as category_id',
                'categories.name as category_name',
                'production_areas.id as production_area_id',
                'production_areas.name as production_area_name',
                'advance_order_products.ordered_quantity',
                'advance_order_products.ordered_quantity_new',
                'advance_order_products.quantity as manual_quantity',
                'advance_order_products.total_to_produce',
                DB::raw('COALESCE(warehouse_product.stock, 0) as current_stock')
            )
            ->get();

        // Get excluded companies data with unique orders (avoid counting duplicates from overlapping OPs)
        // First, get unique order_id + product_id combinations, then sum their quantities
        $uniqueOrderLines = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->join('orders', 'order_lines.order_id', '=', 'orders.id')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds)
            ->where('companies.exclude_from_consolidated_report', true)
            ->select(
                'orders.id as order_id',
                'order_lines.product_id',
                'companies.id as company_id',
                'companies.name as company_name',
                'companies.fantasy_name as company_fantasy_name',
                'order_lines.quantity'
            )
            ->distinct() // Get unique combinations of order + product + company
            ->get();

        // Group by product and company, summing unique order quantities
        $excludedCompaniesData = $uniqueOrderLines->groupBy('product_id')
            ->map(function ($productOrders) {
                return $productOrders->groupBy('company_id')
                    ->map(function ($companyOrders) {
                        $first = $companyOrders->first();
                        return (object) [
                            'company_id' => $first->company_id,
                            'company_name' => $first->company_name,
                            'company_fantasy_name' => $first->company_fantasy_name,
                            'total_quantity' => $companyOrders->sum('quantity')
                        ];
                    })->values();
            });

        // Group by production area, then by product
        $groupedByArea = $allProducts->groupBy('production_area_name');

        // Build result grouped by production area
        $result = $groupedByArea->map(function ($areaProducts, $areaName) use ($advanceOrders, $excludedCompaniesData) {
            // Group products within this area
            $groupedProducts = $areaProducts->groupBy('product_id');

            $products = $groupedProducts->map(function ($productGroup) use ($advanceOrders, $excludedCompaniesData) {
                $firstProduct = $productGroup->first();
                $productId = $firstProduct->product_id;

                // Calculate total ordered_quantity using ordered_quantity_new to avoid counting duplicates from overlapping OPs
                $totalOrderedQuantity = $productGroup->sum('ordered_quantity_new');

                // Build data array
                $productData = [
                    'product_id' => $productId,
                    'product_code' => $firstProduct->product_code,
                    'product_name' => $firstProduct->product_name,
                    'category_id' => $firstProduct->category_id,
                    'category_name' => $firstProduct->category_name ?? 'Sin Categoría',
                    'total_ordered_quantity' => $totalOrderedQuantity,
                    'current_stock' => $firstProduct->current_stock ?? 0,
                    'ops' => [],
                    'companies' => []
                ];

                // Add excluded companies data if exists for this product
                if (isset($excludedCompaniesData[$productId])) {
                    foreach ($excludedCompaniesData[$productId] as $companyData) {
                        $companyKey = 'company_' . $companyData->company_id;

                        $productData['companies'][$companyKey] = [
                            'company_id' => $companyData->company_id,
                            'company_name' => $companyData->company_name,
                            'company_fantasy_name' => $companyData->company_fantasy_name,
                            'total_quantity' => $companyData->total_quantity
                        ];
                    }
                }

                // Add data for each OP
                foreach ($productGroup as $product) {
                    $opOrder = array_search($product->advance_order_id, array_keys($advanceOrders->toArray()));

                    $productData['ops'][$product->advance_order_id] = [
                        'op_order' => $opOrder + 1, // 1-indexed
                        'manual_quantity' => $product->manual_quantity,
                        'total_to_produce' => $product->total_to_produce,
                    ];
                }

                return $productData;
            });

            // Sort products within area by category and code
            $sortedProducts = $products->sortBy([
                ['category_name', 'asc'],
                ['product_code', 'asc']
            ])->values();

            // Calculate total_ordered_quantity for this production area
            $areaTotalOrderedQuantity = $products->sum('total_ordered_quantity');

            // Calculate totals for each excluded company in this area
            $companyTotals = [];
            foreach ($products as $product) {
                if (isset($product['companies'])) {
                    foreach ($product['companies'] as $companyKey => $companyData) {
                        if (!isset($companyTotals[$companyKey])) {
                            $companyTotals[$companyKey] = [
                                'company_id' => $companyData['company_id'],
                                'company_name' => $companyData['company_name'],
                                'company_fantasy_name' => $companyData['company_fantasy_name'],
                                'total_quantity' => 0
                            ];
                        }
                        $companyTotals[$companyKey]['total_quantity'] += $companyData['total_quantity'];
                    }
                }
            }

            // Calculate totals for each OP in this area
            $opTotals = [];
            foreach ($products as $product) {
                if (isset($product['ops'])) {
                    foreach ($product['ops'] as $opId => $opData) {
                        if (!isset($opTotals[$opId])) {
                            $opTotals[$opId] = [
                                'op_order' => $opData['op_order'],
                                'manual_quantity' => 0,
                                'total_to_produce' => 0
                            ];
                        }
                        $opTotals[$opId]['manual_quantity'] += $opData['manual_quantity'];
                        $opTotals[$opId]['total_to_produce'] += $opData['total_to_produce'];
                    }
                }
            }

            return [
                'production_area_name' => $areaName ?? 'Sin Área Productiva',
                'products' => $sortedProducts,
                'total_row' => [
                    'product_id' => null,
                    'product_code' => null,
                    'product_name' => 'TOTAL',
                    'category_id' => null,
                    'category_name' => null,
                    'total_ordered_quantity' => $areaTotalOrderedQuantity,
                    'current_stock' => null,
                    'ops' => $opTotals,
                    'companies' => $companyTotals
                ]
            ];
        });

        // Sort production areas by name
        return $result->sortBy('production_area_name')->values();
    }

    /**
     * Generate description for export process
     * Format: "OPs: 14, 15, 16 | Desde: 04/11/2025 - Hasta: 06/11/2025"
     *
     * @param array $advanceOrderIds
     * @return string
     */
    public function generateExportDescription(array $advanceOrderIds): string
    {
        // Get advance orders
        $advanceOrders = AdvanceOrder::whereIn('id', $advanceOrderIds)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($advanceOrders->isEmpty()) {
            return 'Sin órdenes de producción';
        }

        // Get dispatch date range
        $allDates = collect();
        foreach ($advanceOrders as $order) {
            $allDates->push($order->initial_dispatch_date);
            $allDates->push($order->final_dispatch_date);
        }
        $minDate = $allDates->filter()->min();
        $maxDate = $allDates->filter()->max();

        // Format date range
        $dateRange = 'Desde: ' . \Carbon\Carbon::parse($minDate)->format('d/m/Y') . ' - Hasta: ' . \Carbon\Carbon::parse($maxDate)->format('d/m/Y');

        // Build description
        $idsString = implode(', ', $advanceOrderIds);
        return "OPs: {$idsString} | {$dateRange}";
    }
}
