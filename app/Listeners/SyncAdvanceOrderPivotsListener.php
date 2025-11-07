<?php

namespace App\Listeners;

use App\Events\AdvanceOrderCreated;
use App\Events\AdvanceOrderDatesUpdated;
use App\Events\AdvanceOrderProductChanged;
use App\Events\AdvanceOrderProductsBulkLoaded;
use App\Models\AdvanceOrder;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAdvanceOrderPivotsListener
{
    /**
     * Handle AdvanceOrderCreated event.
     *
     * CRITICAL: This is the ONLY place where pivots are synchronized.
     * Pivots are synced once at creation and NEVER changed afterward.
     *
     * Two scenarios:
     * 1. Created from Filament form: $orderIds = null
     *    - Syncs ALL orders in the date range
     * 2. Created from selected orders: $orderIds = [...]
     *    - Syncs ONLY the specified orders
     */
    public function handleAdvanceOrderCreated(AdvanceOrderCreated $event): void
    {
        Log::info('SyncAdvanceOrderPivotsListener: AdvanceOrderCreated', [
            'advance_order_id' => $event->advanceOrder->id,
            'order_ids_provided' => $event->orderIds !== null,
            'order_ids_count' => $event->orderIds ? count($event->orderIds) : 0,
        ]);

        // Sync pivots ONCE at creation - this will NEVER happen again
        $this->syncAllPivotsOnCreation($event->advanceOrder, $event->orderIds);
    }

    /**
     * Handle AdvanceOrderProductChanged event.
     *
     * This is triggered when products are added manually via Filament RelationManager
     * AFTER the AdvanceOrder was created.
     */
    public function handleAdvanceOrderProductChanged(AdvanceOrderProductChanged $event): void
    {
        Log::info('SyncAdvanceOrderPivotsListener: AdvanceOrderProductChanged', [
            'advance_order_id' => $event->advanceOrderProduct->advance_order_id,
            'product_id' => $event->advanceOrderProduct->product_id,
            'change_type' => $event->changeType,
        ]);

        $advanceOrder = $event->advanceOrderProduct->advanceOrder;

        // Only sync if this is a product being added ('created')
        // This handles the case when OP is created empty from Filament form,
        // then products are added manually
        if ($event->changeType === 'created') {
            // Sync pivots for this product only
            $this->syncPivotsForProduct($advanceOrder, $event->advanceOrderProduct->product_id);
        }
        // NOTE: We DO NOT handle 'updated' or 'deleted' - pivots stay unchanged
    }

    /**
     * Sync ALL orders and order_lines for the entire advance order ON CREATION ONLY.
     *
     * This method is called ONLY when the AdvanceOrder is first created.
     * After creation, pivots are IMMUTABLE and never change.
     *
     * @param AdvanceOrder $advanceOrder
     * @param array|null $specificOrderIds If provided, sync only these order IDs (from creation from selected orders)
     */
    protected function syncAllPivotsOnCreation(AdvanceOrder $advanceOrder, ?array $specificOrderIds = null): void
    {
        DB::beginTransaction();
        try {
            // Get all products in this OP
            $productIds = $advanceOrder->advanceOrderProducts()->pluck('product_id')->toArray();

            // IMPORTANT: If no products exist yet (empty OP created from Filament),
            // we still need to sync orders from date range (if no specific order IDs provided)
            // This allows the OP to be created with all relevant orders, and products can be added later
            if (empty($productIds) && $specificOrderIds !== null) {
                // Case: Specific orders provided but no products yet
                // This shouldn't happen in normal flow, but handle gracefully
                DB::commit();
                return;
            }

            // Determine which orders to sync
            if ($specificOrderIds !== null) {
                // Case 1: Specific order IDs provided (creation from selected orders)
                // Use ONLY these specific orders
                $orders = Order::whereIn('id', $specificOrderIds)
                    ->whereIn('status', ['PROCESSED', 'PARTIALLY_SCHEDULED'])
                    ->with(['orderLines.product', 'user'])
                    ->get();

                // Populate advance_order_orders with selected orders (only if not already there)
                foreach ($orders as $order) {
                    $exists = DB::table('advance_order_orders')
                        ->where('advance_order_id', $advanceOrder->id)
                        ->where('order_id', $order->id)
                        ->exists();

                    if (!$exists) {
                        $this->insertAdvanceOrderOrder($advanceOrder, $order);
                    }
                }
            } else {
                // Case 2: No specific orders provided (manual creation or auto-load)
                // Check if orders already exist in pivot
                $existingOrderIds = DB::table('advance_order_orders')
                    ->where('advance_order_id', $advanceOrder->id)
                    ->pluck('order_id')
                    ->toArray();

                if (empty($existingOrderIds)) {
                    // Get orders from date range (initial creation scenario)
                    $orders = $this->getOrdersInDateRange($advanceOrder);

                    // Populate advance_order_orders for initial creation
                    foreach ($orders as $order) {
                        $this->insertAdvanceOrderOrder($advanceOrder, $order);
                    }
                } else {
                    // Get ONLY the orders that are already associated (re-sync scenario)
                    $orders = Order::whereIn('id', $existingOrderIds)
                        ->whereIn('status', ['PROCESSED', 'PARTIALLY_SCHEDULED'])
                        ->with(['orderLines.product', 'user'])
                        ->get();
                }
            }

            // Clear existing order lines (but NOT orders)
            DB::table('advance_order_order_lines')
                ->where('advance_order_id', $advanceOrder->id)
                ->delete();

            // Populate advance_order_order_lines (only for products in OP)
            foreach ($orders as $order) {
                foreach ($order->orderLines as $orderLine) {
                    if (in_array($orderLine->product_id, $productIds)) {
                        // For PARTIALLY_SCHEDULED orders, only include lines with partially_scheduled = true
                        if ($order->status === 'PARTIALLY_SCHEDULED' && !$orderLine->partially_scheduled) {
                            continue;
                        }

                        $this->insertAdvanceOrderOrderLine($advanceOrder, $order, $orderLine);
                    }
                }
            }

            DB::commit();

            Log::info('SyncAdvanceOrderPivotsListener: syncAllPivotsOnCreation completed', [
                'advance_order_id' => $advanceOrder->id,
                'orders_count' => $orders->count(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('SyncAdvanceOrderPivotsListener: syncAllPivotsOnCreation failed', [
                'advance_order_id' => $advanceOrder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync pivots only for a specific product.
     */
    protected function syncPivotsForProduct(AdvanceOrder $advanceOrder, int $productId): void
    {
        DB::beginTransaction();
        try {
            // Remove existing pivots for this product
            DB::table('advance_order_order_lines')
                ->where('advance_order_id', $advanceOrder->id)
                ->where('product_id', $productId)
                ->delete();

            // Get orders in date range that have this product
            $orders = $this->getOrdersInDateRange($advanceOrder, $productId);

            // For each order with this product
            foreach ($orders as $order) {
                // Check if order is already in advance_order_orders
                $existingOrderPivot = DB::table('advance_order_orders')
                    ->where('advance_order_id', $advanceOrder->id)
                    ->where('order_id', $order->id)
                    ->exists();

                // If not, insert it
                if (!$existingOrderPivot) {
                    $this->insertAdvanceOrderOrder($advanceOrder, $order);
                }

                // Insert order_lines for this product
                foreach ($order->orderLines as $orderLine) {
                    if ($orderLine->product_id === $productId) {
                        // For PARTIALLY_SCHEDULED orders, only include lines with partially_scheduled = true
                        if ($order->status === 'PARTIALLY_SCHEDULED' && !$orderLine->partially_scheduled) {
                            continue;
                        }

                        $this->insertAdvanceOrderOrderLine($advanceOrder, $order, $orderLine);
                    }
                }
            }

            DB::commit();

            Log::info('SyncAdvanceOrderPivotsListener: syncPivotsForProduct completed', [
                'advance_order_id' => $advanceOrder->id,
                'product_id' => $productId,
                'orders_count' => $orders->count(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('SyncAdvanceOrderPivotsListener: syncPivotsForProduct failed', [
                'advance_order_id' => $advanceOrder->id,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Remove pivots for a specific product (when product is deleted from OP).
     */
    protected function removePivotsForProduct(AdvanceOrder $advanceOrder, int $productId): void
    {
        DB::beginTransaction();
        try {
            // Remove order_lines for this product
            DB::table('advance_order_order_lines')
                ->where('advance_order_id', $advanceOrder->id)
                ->where('product_id', $productId)
                ->delete();

            // Find orders that no longer have ANY products in this OP
            $remainingProductIds = $advanceOrder->advanceOrderProducts()
                ->where('product_id', '!=', $productId)
                ->pluck('product_id')
                ->toArray();

            if (empty($remainingProductIds)) {
                // If no products left, remove all order associations
                DB::table('advance_order_orders')
                    ->where('advance_order_id', $advanceOrder->id)
                    ->delete();
            } else {
                // Remove orders that don't have any remaining products
                $ordersToKeep = DB::table('advance_order_order_lines')
                    ->where('advance_order_id', $advanceOrder->id)
                    ->distinct()
                    ->pluck('order_id')
                    ->toArray();

                DB::table('advance_order_orders')
                    ->where('advance_order_id', $advanceOrder->id)
                    ->whereNotIn('order_id', $ordersToKeep)
                    ->delete();
            }

            DB::commit();

            Log::info('SyncAdvanceOrderPivotsListener: removePivotsForProduct completed', [
                'advance_order_id' => $advanceOrder->id,
                'product_id' => $productId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('SyncAdvanceOrderPivotsListener: removePivotsForProduct failed', [
                'advance_order_id' => $advanceOrder->id,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get orders in the date range of the advance order.
     */
    protected function getOrdersInDateRange(AdvanceOrder $advanceOrder, ?int $productId = null)
    {
        $query = Order::whereBetween('dispatch_date', [
                $advanceOrder->initial_dispatch_date->format('Y-m-d'),
                $advanceOrder->final_dispatch_date->format('Y-m-d')
            ])
            ->whereIn('status', ['PROCESSED', 'PARTIALLY_SCHEDULED'])
            ->with(['orderLines.product', 'user']);

        if ($productId !== null) {
            $query->whereHas('orderLines', function($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }

        return $query->get();
    }

    /**
     * Insert a record into advance_order_orders.
     */
    protected function insertAdvanceOrderOrder(AdvanceOrder $advanceOrder, Order $order): void
    {
        DB::table('advance_order_orders')->insert([
            'advance_order_id' => $advanceOrder->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_dispatch_date' => $order->dispatch_date,
            'order_status' => $order->status,
            'order_user_id' => $order->user_id,
            'order_user_nickname' => $order->user->nickname ?? null,
            'order_total' => $order->total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert a record into advance_order_order_lines.
     */
    protected function insertAdvanceOrderOrderLine(AdvanceOrder $advanceOrder, Order $order, $orderLine): void
    {
        DB::table('advance_order_order_lines')->insert([
            'advance_order_id' => $advanceOrder->id,
            'order_line_id' => $orderLine->id,
            'product_id' => $orderLine->product_id,
            'order_id' => $order->id,
            'quantity_covered' => $orderLine->quantity,
            'product_name' => $orderLine->product->name,
            'product_code' => $orderLine->product->code,
            'order_dispatch_date' => $order->dispatch_date,
            'order_number' => $order->order_number,
            'order_line_unit_price' => $orderLine->unit_price,
            'order_line_total_price' => $orderLine->quantity * $orderLine->unit_price,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
