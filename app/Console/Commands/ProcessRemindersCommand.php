<?php

namespace App\Console\Commands;

use App\Enums\CampaignEventType;
use App\Services\Reminders\ProcessRemindersService;
use Illuminate\Console\Command;

class ProcessRemindersCommand extends Command
{
    protected $signature = 'reminders:process {--event= : Specific event type to process (e.g. menu_created)}';

    protected $description = 'Process active reminders and send notifications';

    public function handle(ProcessRemindersService $service): int
    {
        $specificEvent = $this->option('event');

        if ($specificEvent) {
            $eventType = CampaignEventType::tryFrom($specificEvent);

            if (! $eventType) {
                $this->error("Invalid event type: {$specificEvent}");
                $this->info('Available event types: ' . implode(', ', array_column(CampaignEventType::cases(), 'value')));

                return Command::FAILURE;
            }

            $this->info("Processing reminders for event: {$eventType->value}");
            $result = $service->processEventType($eventType);
            $this->outputResult($eventType, $result);
        } else {
            foreach (CampaignEventType::cases() as $eventType) {
                $this->info("Processing reminders for event: {$eventType->value}");

                try {
                    $result = $service->processEventType($eventType);
                    $this->outputResult($eventType, $result);
                } catch (\InvalidArgumentException $e) {
                    $this->warn("  Skipped: {$e->getMessage()}");
                }
            }
        }

        return Command::SUCCESS;
    }

    private function outputResult(CampaignEventType $eventType, array $result): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Event', $eventType->value],
                ['Triggers processed', $result['triggers_processed']],
                ['Sent', $result['sent']],
                ['Pending', $result['pending']],
                ['Failed', $result['failed']],
                ['Skipped', $result['skipped']],
            ]
        );
    }
}