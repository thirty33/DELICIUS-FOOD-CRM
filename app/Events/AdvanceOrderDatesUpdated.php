<?php

namespace App\Events;

use App\Models\AdvanceOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdvanceOrderDatesUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public AdvanceOrder $advanceOrder;
    public string $oldInitialDate;
    public string $oldFinalDate;

    /**
     * Create a new event instance.
     */
    public function __construct(
        AdvanceOrder $advanceOrder,
        string $oldInitialDate,
        string $oldFinalDate
    ) {
        $this->advanceOrder = $advanceOrder;
        $this->oldInitialDate = $oldInitialDate;
        $this->oldFinalDate = $oldFinalDate;
    }
}
