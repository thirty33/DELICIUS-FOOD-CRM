<?php

namespace App\Observers;

use App\Events\AdvanceOrderCreated;
use App\Events\AdvanceOrderDatesUpdated;
use App\Models\AdvanceOrder;

class AdvanceOrderObserver
{
    /**
     * Handle the AdvanceOrder "created" event.
     */
    public function created(AdvanceOrder $advanceOrder): void
    {
        // Fire event to sync pivots for new advance order
        event(new AdvanceOrderCreated($advanceOrder));
    }

    /**
     * Handle the AdvanceOrder "updated" event.
     */
    public function updated(AdvanceOrder $advanceOrder): void
    {
        // Only fire dates updated event if dates actually changed
        // (and not when use_products_in_orders changed, as that's handled by bulk load event)
        if ($advanceOrder->wasChanged(['initial_dispatch_date', 'final_dispatch_date'])
            && !$advanceOrder->wasChanged('use_products_in_orders')) {

            $oldInitialDate = $advanceOrder->getOriginal('initial_dispatch_date');
            $oldFinalDate = $advanceOrder->getOriginal('final_dispatch_date');

            event(new AdvanceOrderDatesUpdated(
                $advanceOrder,
                $oldInitialDate,
                $oldFinalDate
            ));
        }
    }
}
