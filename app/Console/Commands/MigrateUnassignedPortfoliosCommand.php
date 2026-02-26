<?php

namespace App\Console\Commands;

use App\Services\Sellers\MigrateUnassignedPortfoliosService;
use Illuminate\Console\Command;

class MigrateUnassignedPortfoliosCommand extends Command
{
    protected $signature = 'portfolios:migrate-unassigned {--limit=100}';

    protected $description = 'Assign unassigned users to the correct default portfolio based on their order history';

    public function __construct(private MigrateUnassignedPortfoliosService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $totals = $this->service->migrate($limit);

        $this->info("Venta Fresca: {$totals['venta_fresca']}");
        $this->info("Post Venta: {$totals['post_venta']}");
        $this->info("Skipped: {$totals['skipped']}");

        return self::SUCCESS;
    }
}
