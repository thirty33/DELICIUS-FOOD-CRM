<?php

namespace App\Observers;

use App\Jobs\RecordFirstOrderJob;
use App\Models\Order;

class OrderFirstOrderObserver
{
    public function created(Order $order): void
    {
        if (! $order->user_id) {
            return;
        }

        RecordFirstOrderJob::dispatch($order->user_id, $order->created_at->toDateString());
    }
}
