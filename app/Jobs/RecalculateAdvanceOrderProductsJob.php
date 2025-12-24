<?php

namespace App\Jobs;

use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderOrderLine;
use App\Models\AdvanceOrderProduct;
use App\Models\OrderLine;
use App\Repositories\AdvanceOrderRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to recalculate ordered_quantity_new for AdvanceOrderProducts
 * when an OrderLine is modified or deleted.
 *
 * This job:
 * 1. Finds all advance_order_order_lines that reference the order_line
 * 2. Groups them by advance_order_id and product_id
 * 3. Recalculates ordered_quantity_new for each affected AdvanceOrderProduct
 *
 * The recalculation considers:
 * - Current total ordered quantity for the product across all covered order_lines
 * - What was already covered by previous OPs (ordered by creation date)
 * - ordered_quantity_new = max(0, current_total - previously_covered)
 */
class RecalculateAdvanceOrderProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $orderLineId;
    public int $productId;
    public int $orderId;
    public ?int $newQuantity;
    public array $advanceOrderIds;

    /**
     * Create a new job instance.
     *
     * @param int $orderLineId The order line that was modified/deleted
     * @param int $productId The product ID from the order line
     * @param int $orderId The order ID from the order line
     * @param int|null $newQuantity The new quantity (null if line was deleted)
     * @param array $advanceOrderIds The advance order IDs that cover this order line (captured before deletion)
     */
    public function __construct(
        int $orderLineId,
        int $productId,
        int $orderId,
        ?int $newQuantity = null,
        array $advanceOrderIds = []
    ) {
        $this->orderLineId = $orderLineId;
        $this->productId = $productId;
        $this->orderId = $orderId;
        $this->newQuantity = $newQuantity;
        $this->advanceOrderIds = $advanceOrderIds;
    }

    /**
     * Execute the job.
     */
    public function handle(AdvanceOrderRepository $advanceOrderRepository): void
    {
        Log::info('RecalculateAdvanceOrderProductsJob: Starting', [
            'order_line_id' => $this->orderLineId,
            'product_id' => $this->productId,
            'order_id' => $this->orderId,
            'new_quantity' => $this->newQuantity,
            'advance_order_ids' => $this->advanceOrderIds,
        ]);

        // Use the advance_order_ids passed from the Observer (captured before deletion)
        // This is critical because when this job runs async, the pivots may already be deleted
        $advanceOrderIds = collect($this->advanceOrderIds);

        if ($advanceOrderIds->isEmpty()) {
            Log::info('RecalculateAdvanceOrderProductsJob: No OPs provided, skipping', [
                'order_line_id' => $this->orderLineId,
            ]);
            return;
        }

        Log::info('RecalculateAdvanceOrderProductsJob: Processing OPs', [
            'order_line_id' => $this->orderLineId,
            'advance_order_ids' => $advanceOrderIds->toArray(),
        ]);

        DB::beginTransaction();
        try {
            // IMPORTANT: Process OPs in order of creation (oldest first)
            // This ensures that when we calculate overlap for OP2, OP1 is already updated
            $sortedAdvanceOrderIds = AdvanceOrder::whereIn('id', $advanceOrderIds)
                ->orderBy('created_at', 'asc')
                ->pluck('id');

            foreach ($sortedAdvanceOrderIds as $advanceOrderId) {
                $this->recalculateForAdvanceOrder($advanceOrderId, $advanceOrderRepository, $sortedAdvanceOrderIds);
            }

            // Also update the pivot table quantity_covered if line was modified
            if ($this->newQuantity !== null) {
                $this->updatePivotQuantityCovered($sortedAdvanceOrderIds);
            }

            DB::commit();

            Log::info('RecalculateAdvanceOrderProductsJob: Completed successfully', [
                'order_line_id' => $this->orderLineId,
                'advance_order_ids' => $advanceOrderIds->toArray(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('RecalculateAdvanceOrderProductsJob: Error during recalculation', [
                'order_line_id' => $this->orderLineId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Recalculate ordered_quantity_new for a specific AdvanceOrder and product.
     *
     * @param int $advanceOrderId The advance order to recalculate
     * @param AdvanceOrderRepository $advanceOrderRepository Repository instance
     * @param \Illuminate\Support\Collection $allAffectedIds All OP IDs being processed (sorted by creation)
     */
    protected function recalculateForAdvanceOrder(
        int $advanceOrderId,
        AdvanceOrderRepository $advanceOrderRepository,
        $allAffectedIds
    ): void {
        // Find the AdvanceOrderProduct for this OP and product
        $advanceOrderProduct = AdvanceOrderProduct::where('advance_order_id', $advanceOrderId)
            ->where('product_id', $this->productId)
            ->first();

        if (!$advanceOrderProduct) {
            Log::warning('RecalculateAdvanceOrderProductsJob: AdvanceOrderProduct not found', [
                'advance_order_id' => $advanceOrderId,
                'product_id' => $this->productId,
            ]);
            return;
        }

        $advanceOrder = $advanceOrderProduct->advanceOrder;

        // Calculate new ordered_quantity from all order_lines covered by this OP
        // IMPORTANT: We need to use the CURRENT quantities from order_lines,
        // not the cached values in the pivot table
        $newOrderedQuantity = $this->calculateCurrentOrderedQuantity($advanceOrderId);

        // Calculate overlap using ALREADY UPDATED ordered_quantity_new from previous OPs
        // This is critical: previous OPs in our processing order have already been updated,
        // so we use their CURRENT ordered_quantity_new values (not historical pivot data)
        $maxPreviousQuantity = $this->calculateOverlapFromUpdatedOPs(
            $advanceOrderId,
            $allAffectedIds,
            $advanceOrderRepository
        );

        $newOrderedQuantityNew = max(0, $newOrderedQuantity - $maxPreviousQuantity);

        $oldOrderedQuantity = $advanceOrderProduct->ordered_quantity;
        $oldOrderedQuantityNew = $advanceOrderProduct->ordered_quantity_new;

        // Update ONLY ordered_quantity_new, NOT ordered_quantity
        // - ordered_quantity is HISTORICAL (value when OP was created) - used for production calculation
        // - ordered_quantity_new is for the REPORT (what the OP "claims" now)
        $advanceOrderProduct->update([
            'ordered_quantity_new' => $newOrderedQuantityNew,
        ]);

        Log::info('RecalculateAdvanceOrderProductsJob: Updated AdvanceOrderProduct', [
            'advance_order_id' => $advanceOrderId,
            'product_id' => $this->productId,
            'ordered_quantity' => $advanceOrderProduct->ordered_quantity,
            'old_ordered_quantity_new' => $oldOrderedQuantityNew,
            'new_ordered_quantity_new' => $newOrderedQuantityNew,
            'max_previous_quantity' => $maxPreviousQuantity,
        ]);
    }

    /**
     * Calculate overlap using ALREADY UPDATED ordered_quantity_new from previous OPs.
     *
     * Unlike getMaxOrderedQuantityForProduct which uses historical pivot data,
     * this method uses the CURRENT ordered_quantity_new values from previous OPs
     * that have already been processed in this job run.
     *
     * @param int $currentAdvanceOrderId Current OP being calculated
     * @param \Illuminate\Support\Collection $allAffectedIds All OP IDs sorted by creation date
     * @param AdvanceOrderRepository $advanceOrderRepository Repository instance
     * @return int Maximum quantity covered by previous OPs
     */
    protected function calculateOverlapFromUpdatedOPs(
        int $currentAdvanceOrderId,
        $allAffectedIds,
        AdvanceOrderRepository $advanceOrderRepository
    ): int {
        // Get the current OP's position in the sorted list
        $currentPosition = $allAffectedIds->search($currentAdvanceOrderId);

        if ($currentPosition === 0 || $currentPosition === false) {
            // This is the first OP, no previous OPs to overlap with
            return 0;
        }

        // Get all previous OP IDs (those that come before in creation order)
        $previousOpIds = $allAffectedIds->take($currentPosition);

        // Sum the UPDATED ordered_quantity_new from all previous OPs for this product
        $totalPreviousQuantity = AdvanceOrderProduct::whereIn('advance_order_id', $previousOpIds)
            ->where('product_id', $this->productId)
            ->sum('ordered_quantity_new');

        Log::info('RecalculateAdvanceOrderProductsJob: Calculated overlap from updated OPs', [
            'current_advance_order_id' => $currentAdvanceOrderId,
            'previous_op_ids' => $previousOpIds->toArray(),
            'total_previous_quantity' => $totalPreviousQuantity,
        ]);

        return (int) $totalPreviousQuantity;
    }

    /**
     * Calculate the current total ordered quantity for a product in an OP
     * by summing the CURRENT quantities from all covered order_lines.
     *
     * IMPORTANT: If newQuantity is null (deletion), we exclude the order_line
     * being deleted because the Job runs BEFORE the actual delete, so the
     * line still exists in the database but should not be counted.
     */
    protected function calculateCurrentOrderedQuantity(int $advanceOrderId): int
    {
        // Get all order_line_ids covered by this OP for this product
        $orderLineIds = AdvanceOrderOrderLine::where('advance_order_id', $advanceOrderId)
            ->where('product_id', $this->productId)
            ->pluck('order_line_id');

        if ($orderLineIds->isEmpty()) {
            return 0;
        }

        // Build query to sum CURRENT quantities from order_lines table
        $query = OrderLine::whereIn('id', $orderLineIds);

        // CRITICAL: If this job was dispatched due to a DELETE (newQuantity = null),
        // we must EXCLUDE the order_line being deleted from the sum.
        // The line still exists in DB during 'deleting' event, but should not count.
        if ($this->newQuantity === null) {
            $query->where('id', '!=', $this->orderLineId);

            Log::info('RecalculateAdvanceOrderProductsJob: Excluding deleted line from sum', [
                'advance_order_id' => $advanceOrderId,
                'excluded_order_line_id' => $this->orderLineId,
            ]);
        }

        $totalQuantity = $query->sum('quantity');

        return (int) $totalQuantity;
    }

    /**
     * Update quantity_covered in the pivot table for the modified order_line.
     * This recalculates how much of the new quantity each OP covers.
     */
    protected function updatePivotQuantityCovered($advanceOrderIds): void
    {
        // Get the order_line to check its current quantity
        $orderLine = OrderLine::find($this->orderLineId);
        if (!$orderLine) {
            return;
        }

        $remainingQuantity = $orderLine->quantity;

        // Update pivots in order of OP creation (oldest first gets priority)
        $pivots = AdvanceOrderOrderLine::where('order_line_id', $this->orderLineId)
            ->whereIn('advance_order_id', $advanceOrderIds)
            ->join('advance_orders', 'advance_orders.id', '=', 'advance_order_order_lines.advance_order_id')
            ->orderBy('advance_orders.created_at', 'asc')
            ->select('advance_order_order_lines.*')
            ->get();

        foreach ($pivots as $pivot) {
            // This OP covers up to what remains
            $newQuantityCovered = min($remainingQuantity, $pivot->quantity_covered);

            if ($newQuantityCovered != $pivot->quantity_covered) {
                AdvanceOrderOrderLine::where('id', $pivot->id)
                    ->update(['quantity_covered' => $newQuantityCovered]);

                Log::info('RecalculateAdvanceOrderProductsJob: Updated pivot quantity_covered', [
                    'pivot_id' => $pivot->id,
                    'advance_order_id' => $pivot->advance_order_id,
                    'order_line_id' => $this->orderLineId,
                    'old_quantity_covered' => $pivot->quantity_covered,
                    'new_quantity_covered' => $newQuantityCovered,
                ]);
            }

            $remainingQuantity = max(0, $remainingQuantity - $newQuantityCovered);
        }
    }
}