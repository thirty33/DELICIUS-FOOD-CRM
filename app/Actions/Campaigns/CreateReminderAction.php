<?php

namespace App\Actions\Campaigns;

use App\Actions\Contracts\CreateAction;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\TriggerType;
use App\Models\Campaign;
use App\Models\CampaignTrigger;
use Illuminate\Support\Facades\DB;

final class CreateReminderAction implements CreateAction
{
    public static function execute(array $data = []): Campaign
    {
        return DB::transaction(function () use ($data) {
            $campaign = Campaign::create([
                'name' => data_get($data, 'name'),
                'type' => CampaignType::REMINDER->value,
                'channel' => CampaignChannel::WHATSAPP->value,
                'status' => CampaignStatus::DRAFT->value,
                'content' => data_get($data, 'content'),
                'created_by' => auth()->id(),
            ]);

            if ($companies = data_get($data, 'companies')) {
                $campaign->companies()->sync($companies);
            }

            if ($branches = data_get($data, 'branches')) {
                $campaign->branches()->sync($branches);
            }

            CampaignTrigger::create([
                'campaign_id' => $campaign->id,
                'trigger_type' => TriggerType::EVENT->value,
                'event_type' => data_get($data, 'event_type'),
                'hours_before' => data_get($data, 'hours_before'),
                'hours_after' => data_get($data, 'hours_after'),
                'is_active' => true,
            ]);

            return $campaign;
        });
    }
}