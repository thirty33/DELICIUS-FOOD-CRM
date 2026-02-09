<?php

namespace App\Services\Reminders;

use App\Enums\CampaignEventType;
use App\Services\Reminders\Contracts\ReminderEventStrategy;
use App\Services\Reminders\Strategies\MenuCreatedStrategy;

class ReminderStrategyFactory
{
    public static function create(CampaignEventType $eventType): ReminderEventStrategy
    {
        return match ($eventType) {
            CampaignEventType::MENU_CREATED => app(MenuCreatedStrategy::class),
            default => throw new \InvalidArgumentException(
                "No reminder strategy found for event type: {$eventType->value}"
            ),
        };
    }
}