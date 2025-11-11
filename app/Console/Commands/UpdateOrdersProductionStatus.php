<?php

namespace App\Console\Commands;

use App\Services\OrderProductionStatusService;
use Illuminate\Console\Command;

class UpdateOrdersProductionStatus extends Command
{
    protected OrderProductionStatusService $service;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:update-production-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update production status for orders that need recalculation';

    public function __construct(OrderProductionStatusService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting production status update...');

        $updatedCount = $this->service->updateOrdersNeedingRecalculation(15);

        if ($updatedCount === 0) {
            $this->info('No orders need production status update.');
        } else {
            $this->info("Updated {$updatedCount} orders successfully.");
        }

        return self::SUCCESS;
    }
}
