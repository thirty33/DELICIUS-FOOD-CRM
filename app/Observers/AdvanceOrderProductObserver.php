<?php

namespace App\Observers;

use App\Events\AdvanceOrderProductChanged;
use App\Models\AdvanceOrderProduct;

class AdvanceOrderProductObserver
{
    /**
     * Handle the AdvanceOrderProduct "creating" event.
     */
    public function creating(AdvanceOrderProduct $advanceOrderProduct): void
    {
        $totalToProduce = $advanceOrderProduct->calculateTotalToProduce();

        \Illuminate\Support\Facades\Log::debug('AdvanceOrderProductObserver: calculating total_to_produce', [
            'advance_order_id' => $advanceOrderProduct->advance_order_id,
            'product_id' => $advanceOrderProduct->product_id,
            'ordered_quantity' => $advanceOrderProduct->ordered_quantity,
            'ordered_quantity_new' => $advanceOrderProduct->ordered_quantity_new,
            'quantity' => $advanceOrderProduct->quantity,
            'calculated_total_to_produce' => $totalToProduce,
        ]);

        $advanceOrderProduct->total_to_produce = $totalToProduce;
    }

    /**
     * Handle the AdvanceOrderProduct "created" event.
     *
     * This event fires when a product is added manually to an AdvanceOrder
     * (e.g., via Filament RelationManager after OP was created).
     *
     * This allows syncing pivots for this specific product when added to an
     * AdvanceOrder that was created empty from Filament form.
     */
    public function created(AdvanceOrderProduct $advanceOrderProduct): void
    {
        // Fire event to sync pivots for this specific product
        event(new AdvanceOrderProductChanged($advanceOrderProduct, 'created'));
    }

    /**
     * Handle the AdvanceOrderProduct "updating" event.
     */
    public function updating(AdvanceOrderProduct $advanceOrderProduct): void
    {
        // Only recalculate if quantity changed
        if ($advanceOrderProduct->isDirty('quantity')) {
            $advanceOrderProduct->total_to_produce = $advanceOrderProduct->calculateTotalToProduce();
        }
    }

    /**
     * Handle the AdvanceOrderProduct "updated" event.
     *
     * COMMENTED OUT: This event should NOT sync pivots when editing product quantity.
     * This was causing the bug where editing "Adelantar" field added unselected orders.
     * Pivot synchronization happens ONLY during AdvanceOrder creation via AdvanceOrderCreated event.
     */
    // public function updated(AdvanceOrderProduct $advanceOrderProduct): void
    // {
    //     // Fire event to sync pivots for this specific product
    //     event(new AdvanceOrderProductChanged($advanceOrderProduct, 'updated'));
    // }

    /**
     * Handle the AdvanceOrderProduct "deleted" event.
     *
     * COMMENTED OUT: This event should NOT sync pivots when deleting a product manually.
     * Deleting a product from OP should only remove the AdvanceOrderProduct record.
     * The associated orders should remain in the OP (they may have other products).
     * Pivot synchronization happens ONLY during AdvanceOrder creation via AdvanceOrderCreated event.
     */
    // public function deleted(AdvanceOrderProduct $advanceOrderProduct): void
    // {
    //     // Fire event to sync pivots (remove associations for this product)
    //     event(new AdvanceOrderProductChanged($advanceOrderProduct, 'deleted'));
    // }
}
