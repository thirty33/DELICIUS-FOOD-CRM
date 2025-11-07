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
}
