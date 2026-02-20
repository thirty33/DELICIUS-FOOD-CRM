<?php

namespace App\Console\Commands;

use App\Services\Sellers\PortfolioSyncService;
use Illuminate\Console\Command;

class PortfoliosSyncCommand extends Command
{
    protected $signature = 'portfolios:sync';

    protected $description = 'Sync user_portfolio records for all clients based on their current seller assignment';

    public function __construct(private PortfolioSyncService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->service->sync();

        return self::SUCCESS;
    }
}
