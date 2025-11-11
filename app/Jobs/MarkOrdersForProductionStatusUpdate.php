<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class MarkOrdersForProductionStatusUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $orderIds
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (empty($this->orderIds)) {
            return;
        }

        // Mark orders as needing production status update
        DB::table('orders')
            ->whereIn('id', $this->orderIds)
            ->update(['production_status_needs_update' => true]);
    }
}
