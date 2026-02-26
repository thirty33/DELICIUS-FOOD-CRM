<?php

namespace App\Jobs;

use App\Services\ProductDisplayOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplyProductDisplayOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        protected int $menuId,
    ) {}

    public function handle(ProductDisplayOrderService $service): void
    {
        Log::info('Starting ApplyProductDisplayOrderJob', [
            'menu_id' => $this->menuId,
        ]);

        $result = $service->processMenu($this->menuId);

        if ($result['status'] === 'not_found') {
            Log::warning('Menu not found', ['menu_id' => $this->menuId]);

            return;
        }

        Log::info('ApplyProductDisplayOrderJob completed', [
            'menu_id' => $this->menuId,
            ...$result,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ApplyProductDisplayOrderJob failed', [
            'menu_id' => $this->menuId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
