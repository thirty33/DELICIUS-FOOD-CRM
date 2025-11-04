<?php

namespace App\Events;

use App\Models\AdvanceOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when products are bulk loaded (e.g., when "use_products_in_orders" is enabled)
 * This indicates that ALL products from orders in date range should be associated.
 */
class AdvanceOrderProductsBulkLoaded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public AdvanceOrder $advanceOrder;

    /**
     * Create a new event instance.
     */
    public function __construct(AdvanceOrder $advanceOrder)
    {
        $this->advanceOrder = $advanceOrder;
    }
}
