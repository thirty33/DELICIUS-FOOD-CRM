<?php

namespace App\Services\Reminders\Contracts;

use App\Enums\CampaignEventType;
use App\Models\Campaign;
use App\Models\CampaignTrigger;
use Illuminate\Support\Collection;

interface ReminderEventStrategy
{
    public function getEventType(): CampaignEventType;

    /**
     * Returns the hours field used by this event type ('hours_after' or 'hours_before').
     */
    public function getHoursField(): string;

    /**
     * Get entities eligible for notification based on the trigger configuration.
     * Each strategy determines what "entity" means (menus, categories, users, etc.)
     */
    public function getEligibleEntities(CampaignTrigger $trigger, array $roleIds, array $permissionIds): Collection;

    /**
     * Build the message content replacing placeholders with actual data.
     */
    public function buildMessageContent(Campaign $campaign, Collection $entities): string;
}
