<?php

namespace App\Repositories;

use App\Enums\CampaignEventType;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\CampaignTrigger;
use Illuminate\Support\Collection;

class CampaignTriggerRepository
{
    public function getActiveReminderTriggersByEventType(CampaignEventType $eventType): Collection
    {
        return CampaignTrigger::query()
            ->where('event_type', $eventType->value)
            ->where('is_active', true)
            ->whereHas('campaign', function ($query) {
                $query->where('type', CampaignType::REMINDER->value)
                    ->where('status', CampaignStatus::ACTIVE->value);
            })
            ->with(['campaign.companies.branches', 'campaign.branches'])
            ->get();
    }
}