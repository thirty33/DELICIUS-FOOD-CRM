<?php

namespace App\Events;

use App\Models\AdvanceOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdvanceOrderCancelled
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
