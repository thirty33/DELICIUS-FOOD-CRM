<?php

namespace App\Jobs;

use App\Actions\Sellers\RecordFirstOrderAction;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordFirstOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private int $userId,
        private string $orderDate,
    ) {}

    public function handle(): void
    {
        RecordFirstOrderAction::execute([
            'user_id' => $this->userId,
            'order_date' => Carbon::parse($this->orderDate),
        ]);
    }
}
