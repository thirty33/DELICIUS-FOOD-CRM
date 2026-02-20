<?php

namespace App\Console\Commands;

use App\Services\Sellers\CloseCycleService;
use Illuminate\Console\Command;

class PortfoliosCloseCycleCommand extends Command
{
    protected $signature = 'portfolios:close-cycle';

    protected $description = 'Migrate clients whose month_closed_at has expired to the successor portfolio';

    public function __construct(private CloseCycleService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->service->run();

        return self::SUCCESS;
    }
}
