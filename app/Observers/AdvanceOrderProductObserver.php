<?php

namespace App\Observers;

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
     * Handle the AdvanceOrderProduct "updating" event.
     */
    public function updating(AdvanceOrderProduct $advanceOrderProduct): void
    {
        // Only recalculate if quantity changed
        if ($advanceOrderProduct->isDirty('quantity')) {
            $advanceOrderProduct->total_to_produce = $advanceOrderProduct->calculateTotalToProduce();
        }
    }
}
