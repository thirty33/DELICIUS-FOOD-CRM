<?php

namespace App\Services;

use App\Enums\OrderProductionStatus;
use App\Models\Order;
use App\Repositories\OrderRepository;
use Illuminate\Support\Facades\Log;

/**
 * Service to calculate and update production status for orders.
 *
 * This service encapsulates the logic to determine if an order is:
 * - FULLY_PRODUCED: All products fully covered by EXECUTED OPs
 * - PARTIALLY_PRODUCED: At least one product covered (fully or partially), but not all
 * - NOT_PRODUCED: No products covered by EXECUTED OPs
 */
class OrderProductionStatusService
{
    protected OrderRepository $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    /**
     * Update production status for orders that need recalculation.
     *
     * @param int $limit Maximum number of orders to process
     * @return int Number of orders updated
     */
    public function updateOrdersNeedingRecalculation(int $limit = 15): int
    {
        $orders = Order::where('production_status_needs_update', true)
            ->limit($limit)
            ->get();

        Log::info('OrderProductionStatusService: Query results', [
            'orders_count' => $orders->count(),
            'order_ids' => $orders->pluck('id')->toArray(),
        ]);

        if ($orders->isEmpty()) {
            return 0;
        }

        $updatedCount = 0;

        foreach ($orders as $order) {
            try {
                Log::info('OrderProductionStatusService: Processing order', [
                    'order_id' => $order->id,
                    'current_status' => $order->production_status,
                    'order_status' => $order->status,
                ]);

                $newStatus = $this->calculateProductionStatus($order);

                Log::info('OrderProductionStatusService: Calculated new status', [
                    'order_id' => $order->id,
                    'old_status' => $order->production_status,
                    'new_status' => $newStatus->value,
                ]);

                $updateResult = $order->update([
                    'production_status' => $newStatus->value,
                    'production_status_needs_update' => false,
                ]);

                $updatedCount++;

                // Verify update was saved
                $order->refresh();

                Log::info('OrderProductionStatusService: Order updated', [
                    'order_id' => $order->id,
                    'update_result' => $updateResult,
                    'production_status_after' => $order->production_status,
                    'needs_update_after' => $order->production_status_needs_update,
                ]);
            } catch (\Exception $e) {
                Log::error('OrderProductionStatusService: Error updating order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return $updatedCount;
    }

    /**
     * Calculate production status for an order.
     *
     * An order is FULLY_PRODUCED when:
     * - All order_lines with their CURRENT quantities are fully covered
     *   by the sum of total_to_produce from EXECUTED OPs
     *
     * Logic:
     * - FULLY_PRODUCED: All products fully covered by EXECUTED OPs
     * - PARTIALLY_PRODUCED: At least one product covered (fully or partially), but not all
     * - NOT_PRODUCED: No products covered by EXECUTED OPs
     *
     * @param Order $order
     * @return OrderProductionStatus
     */
    public function calculateProductionStatus(Order $order): OrderProductionStatus
    {
        $orderLines = $order->orderLines;

        Log::info('OrderProductionStatusService: calculateProductionStatus start', [
            'order_id' => $order->id,
            'order_lines_count' => $orderLines->count(),
        ]);

        if ($orderLines->isEmpty()) {
            return OrderProductionStatus::NOT_PRODUCED;
        }

        $fullyProducedCount = 0;
        $partiallyProducedCount = 0;
        $relevantLinesCount = 0;

        foreach ($orderLines as $line) {
            $relevantLinesCount++;

            // Get total produced for this product across ALL EXECUTED OPs using repository
            $totalProduced = $this->orderRepository->getTotalProducedForProduct($order->id, $line->product_id);

            Log::info('OrderProductionStatusService: Checking product', [
                'order_id' => $order->id,
                'product_id' => $line->product_id,
                'line_quantity' => $line->quantity,
                'total_produced' => $totalProduced,
            ]);

            if ($totalProduced == 0) {
                // Product not produced at all
                continue;
            } elseif ($totalProduced >= $line->quantity) {
                // Product fully produced (current quantity is fully covered)
                $fullyProducedCount++;
            } else {
                // Product partially produced (current quantity is only partially covered)
                $partiallyProducedCount++;
            }
        }

        Log::info('OrderProductionStatusService: calculateProductionStatus summary', [
            'order_id' => $order->id,
            'relevantLinesCount' => $relevantLinesCount,
            'fullyProducedCount' => $fullyProducedCount,
            'partiallyProducedCount' => $partiallyProducedCount,
        ]);

        // If all relevant products are fully produced
        if ($relevantLinesCount > 0 && $fullyProducedCount == $relevantLinesCount) {
            return OrderProductionStatus::FULLY_PRODUCED;
        }

        // If at least one product has been produced (fully or partially)
        if ($fullyProducedCount > 0 || $partiallyProducedCount > 0) {
            return OrderProductionStatus::PARTIALLY_PRODUCED;
        }

        // No products produced
        return OrderProductionStatus::NOT_PRODUCED;
    }
}
