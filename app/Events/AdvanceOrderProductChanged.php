<?php

namespace App\Events;

use App\Models\AdvanceOrderProduct;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdvanceOrderProductChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public AdvanceOrderProduct $advanceOrderProduct;
    public string $changeType; // 'created', 'updated', 'deleted'

    /**
     * Create a new event instance.
     */
    public function __construct(
        AdvanceOrderProduct $advanceOrderProduct,
        string $changeType
    ) {
        $this->advanceOrderProduct = $advanceOrderProduct;
        $this->changeType = $changeType;
    }
}
