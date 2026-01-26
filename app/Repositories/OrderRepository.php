<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\AdvanceOrder;
use App\Models\OrderLine;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OrderRepository
{
    protected AdvanceOrderRepository $advanceOrderRepository;
    protected AdvanceOrderProductRepository $advanceOrderProductRepository;

    public function __construct(
        ?AdvanceOrderRepository $advanceOrderRepository = null,
        ?AdvanceOrderProductRepository $advanceOrderProductRepository = null
    ) {
        $this->advanceOrderRepository = $advanceOrderRepository ?? app(AdvanceOrderRepository::class);
        $this->advanceOrderProductRepository = $advanceOrderProductRepository ?? app(AdvanceOrderProductRepository::class);
    }
    /**
     * Filter orders by roles, permissions, branches, and statuses
     *
     * @param Collection $orders Collection of Order models or IDs
     * @param array $filters Array with keys: user_roles, agreement_types, branch_ids, order_statuses
     * @return array Filtered order IDs
     */
    public function filterOrdersByRolesAndStatuses(Collection $orders, array $filters): array
    {
        // Get order IDs from collection
        $orderIds = $orders->pluck('id')->toArray();

        // Start building query
        $query = Order::whereIn('id', $orderIds)
            ->whereIn('status', $filters['order_statuses'] ?? []);

        // Apply user role filter if provided
        if (!empty($filters['user_roles'])) {
            $query->whereHas('user.roles', function (Builder $q) use ($filters) {
                $q->whereIn('name', $filters['user_roles']);
            });
        }

        // Apply agreement type (permission) filter if provided
        if (!empty($filters['agreement_types'])) {
            $query->whereHas('user.permissions', function (Builder $q) use ($filters) {
                $q->whereIn('name', $filters['agreement_types']);
            });
        }

        // Apply branch filter if provided
        if (!empty($filters['branch_ids'])) {
            $query->whereHas('user.branch', function (Builder $q) use ($filters) {
                $q->whereIn('branches.id', $filters['branch_ids']);
            });
        }

        return $query->pluck('id')->toArray();
    }

    /**
     * Check if filtered result is empty
     *
     * @param array $filteredOrderIds
     * @return bool
     */
    public function hasFilteredOrders(array $filteredOrderIds): bool
    {
        return !empty($filteredOrderIds);
    }

    /**
     * Get unique products with quantities from orders within a date range
     *
     * Only includes products from:
     * - PROCESSED orders (all order lines)
     * - PARTIALLY_SCHEDULED orders (only order lines with partially_scheduled = true)
     *
     * Excludes:
     * - PENDING orders
     * - CANCELED orders
     * - Order lines with partially_scheduled = false from PARTIALLY_SCHEDULED orders
     *
     * @param string $startDate
     * @param string $endDate
     * @return Collection Collection of arrays with 'product_id' and 'ordered_quantity'
     */
    public function getProductsFromOrdersInDateRange(string $startDate, string $endDate): Collection
    {
        $orders = Order::whereBetween('dispatch_date', [$startDate, $endDate])
            ->whereIn('status', [\App\Enums\OrderStatus::PROCESSED->value, \App\Enums\OrderStatus::PARTIALLY_SCHEDULED->value])
            ->with('orderLines.product')
            ->get();

        $filteredOrderLines = collect();

        foreach ($orders as $order) {
            foreach ($order->orderLines as $orderLine) {
                // Include all order lines from PROCESSED orders
                if ($order->status === \App\Enums\OrderStatus::PROCESSED->value) {
                    $filteredOrderLines->push($orderLine);
                    continue;
                }

                // For PARTIALLY_SCHEDULED orders, only include lines with partially_scheduled = true
                if ($order->status === \App\Enums\OrderStatus::PARTIALLY_SCHEDULED->value && $orderLine->partially_scheduled) {
                    $filteredOrderLines->push($orderLine);
                }
            }
        }

        return $filteredOrderLines
            ->groupBy('product_id')
            ->map(function ($orderLines, $productId) {
                return [
                    'product_id' => $productId,
                    'ordered_quantity' => $orderLines->sum('quantity'),
                ];
            })
            ->values();
    }

    /**
     * Create an AdvanceOrder from selected orders
     *
     * Only includes:
     * - PROCESSED orders (all order lines)
     * - PARTIALLY_SCHEDULED orders (only order lines with partially_scheduled = true)
     * - Order lines belonging to selected production areas
     *
     * @param array $orderIds Array of order IDs
     * @param string $preparationDatetime Preparation date and time
     * @param array $productionAreaIds Array of production area IDs
     * @return AdvanceOrder Created advance order
     */
    public function createAdvanceOrderFromOrders(
        array $orderIds,
        string $preparationDatetime,
        array $productionAreaIds
    ): AdvanceOrder {
        // Get orders with PROCESSED and PARTIALLY_SCHEDULED status
        $orders = Order::whereIn('id', $orderIds)
            ->whereIn('status', [
                OrderStatus::PROCESSED->value,
                OrderStatus::PARTIALLY_SCHEDULED->value
            ])
            ->with(['orderLines.product.productionAreas'])
            ->get();

        if ($orders->isEmpty()) {
            throw new \Exception('No se encontraron pedidos con estado PROCESSED o PARTIALLY_SCHEDULED.');
        }

        // Calculate date range from all orders
        $allDispatchDates = $orders->pluck('dispatch_date')->filter();

        if ($allDispatchDates->isEmpty()) {
            throw new \Exception('Los pedidos seleccionados no tienen fechas de despacho.');
        }

        $minDate = $allDispatchDates->min();
        $maxDate = $allDispatchDates->max();

        // Create advance order using repository
        $advanceOrder = $this->advanceOrderRepository->createAdvanceOrder(
            $preparationDatetime,
            $minDate,
            $maxDate,
            false
        );

        // Filter and collect order lines
        $filteredOrderLines = collect();

        foreach ($orders as $order) {
            foreach ($order->orderLines as $orderLine) {
                // Check if product belongs to selected production areas
                $productAreaIds = $orderLine->product->productionAreas->pluck('id')->toArray();

                // If product has no production areas or none of them match selected areas, skip
                if (empty($productAreaIds) || empty(array_intersect($productAreaIds, $productionAreaIds))) {
                    continue;
                }

                // Include all order lines from PROCESSED orders
                if ($order->status === OrderStatus::PROCESSED->value) {
                    $filteredOrderLines->push($orderLine);
                    continue;
                }

                // For PARTIALLY_SCHEDULED orders, only include lines with partially_scheduled = true
                if ($order->status === OrderStatus::PARTIALLY_SCHEDULED->value && $orderLine->partially_scheduled) {
                    $filteredOrderLines->push($orderLine);
                }
            }
        }

        if ($filteredOrderLines->isEmpty()) {
            $advanceOrder->delete();
            throw new \Exception('No se encontraron líneas de pedido que cumplan con los criterios seleccionados.');
        }

        // Group order lines by product and sum quantities
        $productQuantities = $filteredOrderLines
            ->groupBy('product_id')
            ->map(function ($orderLines) {
                return [
                    'product_id' => $orderLines->first()->product_id,
                    'ordered_quantity' => $orderLines->sum('quantity'),
                ];
            });

        // Get previous advance orders to calculate ordered_quantity_new
        $previousAdvanceOrders = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($advanceOrder);

        // Create AdvanceOrderProduct instances using repository
        // This ensures total_to_produce is calculated correctly with warehouse stock
        foreach ($productQuantities as $productData) {
            $currentOrderedQuantity = $productData['ordered_quantity'];
            $productId = $productData['product_id'];

            // Get max ordered quantity from previous advance orders
            $maxPreviousQuantity = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
                $productId,
                $previousAdvanceOrders,
                $advanceOrder
            );

            $orderedQuantityNew = max(0, $currentOrderedQuantity - $maxPreviousQuantity);

            // Create AdvanceOrderProduct with proper total_to_produce calculation
            // The repository method considers warehouse stock when calculating
            $this->advanceOrderProductRepository->createAdvanceOrderProduct(
                $advanceOrder->id,
                $productId,
                $currentOrderedQuantity,
                $orderedQuantityNew,
                0 // manual_quantity = 0 for orders created from selection
            );
        }

        // Attach production areas to advance order
        $advanceOrder->productionAreas()->attach($productionAreaIds);

        // Fire AdvanceOrderCreated event with selected order IDs
        // This tells the listener which specific orders to sync (not all orders in the date range)
        event(new \App\Events\AdvanceOrderCreated($advanceOrder, $orderIds));

        return $advanceOrder;
    }

    /**
     * Get the total quantity produced for a specific product in an order
     * by calculating proportionally based on ordered_quantity_new from EXECUTED advance orders.
     *
     * The quantity_covered in pivot represents the total quantity of the order_line at OP creation time,
     * but we need to calculate the actual produced quantity based on ordered_quantity_new
     * (which accounts for what was already covered by previous OPs).
     *
     * FIX: The previous proportional formula was incorrect when multiple orders share
     * the same OP and overlap occurs. The new approach sums ordered_quantity_new from
     * all executed OPs covering this order+product, capped by the order line quantity.
     *
     * @param int $orderId
     * @param int $productId
     * @return int Total quantity produced
     */
    public function getTotalProducedForProduct(int $orderId, int $productId): int
    {
        // Get all EXECUTED OPs that cover this order and product
        $ops = \DB::table('advance_order_order_lines as aool')
            ->join('advance_orders as ao', 'aool.advance_order_id', '=', 'ao.id')
            ->join('advance_order_products as aop', function ($join) use ($productId) {
                $join->on('aop.advance_order_id', '=', 'ao.id')
                     ->where('aop.product_id', '=', $productId);
            })
            ->where('aool.order_id', $orderId)
            ->where('aool.product_id', $productId)
            ->where('ao.status', \App\Enums\AdvanceOrderStatus::EXECUTED->value)
            ->select(
                'aool.quantity_covered',
                'aop.ordered_quantity',
                'aop.ordered_quantity_new'
            )
            ->get();

        $totalProduced = 0;

        foreach ($ops as $op) {
            // Hybrid formula to handle two scenarios correctly:
            //
            // 1. When oq_new > qc (order quantity increased, oq_new recalculated):
            //    Use proportional formula: qc * (oq_new / oq)
            //    This gives credit for the increased production.
            //
            // 2. When oq_new <= qc (overlap from OTHER orders reduced oq_new):
            //    Use min(qc, oq_new) to cap this order's contribution.
            //    This fixes the bug where orders not causing overlap were penalized.
            //
            if ($op->ordered_quantity_new > $op->quantity_covered && $op->ordered_quantity > 0) {
                // Case 1: oq_new increased beyond qc (recalculation scenario)
                $proportion = $op->ordered_quantity_new / $op->ordered_quantity;
                $contribution = $op->quantity_covered * $proportion;
            } else {
                // Case 2: oq_new <= qc (overlap from other orders)
                $contribution = min($op->quantity_covered, $op->ordered_quantity_new);
            }

            $totalProduced += $contribution;
        }

        return (int) round($totalProduced);
    }

    /**
     * Get detailed production status breakdown for an order.
     *
     * Returns comprehensive analysis of which products are produced,
     * partially produced, or not produced, including quantities and percentages.
     *
     * @param Order $order
     * @return array Production detail with summary and per-product breakdown
     */
    public function getProductionDetail(Order $order): array
    {
        $orderLines = $order->orderLines;

        if ($orderLines->isEmpty()) {
            return [
                'production_status' => \App\Enums\OrderProductionStatus::NOT_PRODUCED->value,
                'summary' => [
                    'total_products' => 0,
                    'fully_produced_count' => 0,
                    'partially_produced_count' => 0,
                    'not_produced_count' => 0,
                    'total_coverage_percentage' => 0,
                ],
                'products' => [],
            ];
        }

        $products = [];
        $fullyProducedCount = 0;
        $partiallyProducedCount = 0;
        $notProducedCount = 0;
        $totalRequired = 0;
        $totalProduced = 0;

        foreach ($orderLines as $line) {
            $requiredQuantity = $line->quantity;
            $totalRequired += $requiredQuantity;

            // Get produced quantity using existing method
            $producedQuantity = $this->getTotalProducedForProduct($order->id, $line->product_id);
            $totalProduced += $producedQuantity;

            $pendingQuantity = max(0, $requiredQuantity - $producedQuantity);
            $coveragePercentage = $requiredQuantity > 0
                ? ($producedQuantity / $requiredQuantity) * 100
                : 0;

            // Determine product status using enum
            if ($producedQuantity == 0) {
                $status = \App\Enums\OrderProductionStatus::NOT_PRODUCED->value;
                $notProducedCount++;
            } elseif ($producedQuantity >= $requiredQuantity) {
                $status = \App\Enums\OrderProductionStatus::FULLY_PRODUCED->value;
                $fullyProducedCount++;
            } else {
                $status = \App\Enums\OrderProductionStatus::PARTIALLY_PRODUCED->value;
                $partiallyProducedCount++;
            }

            $products[] = [
                'product_id' => $line->product_id,
                'product_name' => $line->product->name,
                'product_code' => $line->product->code,
                'required_quantity' => $requiredQuantity,
                'produced_quantity' => $producedQuantity,
                'pending_quantity' => $pendingQuantity,
                'coverage_percentage' => round($coveragePercentage, 1),
                'status' => $status,
            ];
        }

        $totalCoveragePercentage = $totalRequired > 0
            ? ($totalProduced / $totalRequired) * 100
            : 0;

        return [
            'production_status' => $order->production_status,
            'summary' => [
                'total_products' => $orderLines->count(),
                'fully_produced_count' => $fullyProducedCount,
                'partially_produced_count' => $partiallyProducedCount,
                'not_produced_count' => $notProducedCount,
                'total_coverage_percentage' => round($totalCoveragePercentage, 1),
            ],
            'products' => $products,
        ];
    }

    /**
     * Get the immediately previous order for a user before a given date.
     *
     * @param int $userId
     * @param string $date Date in Y-m-d format
     * @return Order|null
     */
    public function getPreviousOrder(int $userId, string $date): ?Order
    {
        return Order::with(['orderLines.product.category.subcategories'])
            ->where('user_id', $userId)
            ->where('dispatch_date', '<', $date)
            ->orderBy('dispatch_date', 'desc')
            ->first();
    }

    /**
     * Delete order lines that were not included in an import.
     *
     * Uses individual deletion (not bulk) to ensure observers are triggered,
     * which handles surplus creation for produced items.
     *
     * @param array $orderIds Order IDs to check
     * @param array $trackedOrderLineIds Order line IDs that should NOT be deleted
     * @return int Total number of lines deleted
     */
    public function deleteUnimportedOrderLines(array $orderIds, array $trackedOrderLineIds): int
    {
        $totalDeleted = 0;

        foreach ($orderIds as $orderId) {
            $linesToDelete = OrderLine::where('order_id', $orderId)
                ->whereNotIn('id', $trackedOrderLineIds)
                ->get();

            if ($linesToDelete->isEmpty()) {
                continue;
            }

            // Delete individually to trigger observers (for surplus handling)
            foreach ($linesToDelete as $orderLine) {
                $orderLine->delete();
            }

            \Illuminate\Support\Facades\Log::info('OrderRepository: Deleted unimported order lines', [
                'order_id' => $orderId,
                'deleted_count' => $linesToDelete->count(),
            ]);

            $totalDeleted += $linesToDelete->count();
        }

        return $totalDeleted;
    }
}
