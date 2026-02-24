<?php

namespace App\Services\Reminders;

use App\Enums\CampaignEventType;
use App\Services\Reminders\Contracts\ReminderEventStrategy;
use App\Services\Reminders\Strategies\MenuClosingStrategy;
use App\Services\Reminders\Strategies\MenuCreatedStrategy;

class ReminderStrategyFactory
{
    public static function create(CampaignEventType $eventType): ReminderEventStrategy
    {
        return match ($eventType) {
            CampaignEventType::MENU_CREATED => app(MenuCreatedStrategy::class),
            CampaignEventType::MENU_CLOSING, CampaignEventType::NO_ORDER_PLACED => app(MenuClosingStrategy::class),
            default => throw new \InvalidArgumentException(
                "No reminder strategy found for event type: {$eventType->value}"
            ),
        };
    }
}
