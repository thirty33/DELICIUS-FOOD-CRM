<?php

namespace App\Console\Commands;

use App\Services\Reminders\CheckPendingRemindersService;
use Illuminate\Console\Command;

class CheckPendingRemindersCommand extends Command
{
    protected $signature = 'reminders:check-pending';

    protected $description = 'Check pending reminder notifications and process them based on conversation status';

    public function handle(CheckPendingRemindersService $service): int
    {
        $this->info('Checking pending reminder notifications...');

        $result = $service->checkAll();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total checked', $result['total_checked']],
                ['Sent', $result['sent']],
                ['Expired', $result['expired']],
                ['Unchanged', $result['unchanged']],
            ]
        );

        return Command::SUCCESS;
    }
}
