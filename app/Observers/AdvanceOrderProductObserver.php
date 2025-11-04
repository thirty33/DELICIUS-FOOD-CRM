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
        $advanceOrderProduct->total_to_produce = $advanceOrderProduct->calculateTotalToProduce();
    }

    /**
     * Handle the AdvanceOrderProduct "created" event.
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
     */
    public function updated(AdvanceOrderProduct $advanceOrderProduct): void
    {
        // Fire event to sync pivots for this specific product
        event(new AdvanceOrderProductChanged($advanceOrderProduct, 'updated'));
    }

    /**
     * Handle the AdvanceOrderProduct "deleted" event.
     */
    public function deleted(AdvanceOrderProduct $advanceOrderProduct): void
    {
        // Fire event to sync pivots (remove associations for this product)
        event(new AdvanceOrderProductChanged($advanceOrderProduct, 'deleted'));
    }
}
