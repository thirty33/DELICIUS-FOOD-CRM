<?php

namespace App\Events;

use App\Models\AdvanceOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdvanceOrderCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public AdvanceOrder $advanceOrder;
    public ?array $orderIds;

    /**
     * Create a new event instance.
     *
     * @param AdvanceOrder $advanceOrder
     * @param array|null $orderIds Optional: specific order IDs to sync (for creation from selected orders)
     */
    public function __construct(AdvanceOrder $advanceOrder, ?array $orderIds = null)
    {
        $this->advanceOrder = $advanceOrder;
        $this->orderIds = $orderIds;
    }
}
