<?php

namespace App\Actions\Campaigns;

use App\Actions\Contracts\UpdateAction;
use App\Enums\TriggerType;
use App\Models\Campaign;
use App\Models\CampaignTrigger;
use Illuminate\Support\Facades\DB;

final class UpdateReminderAction implements UpdateAction
{
    public static function execute(array $data = []): Campaign
    {
        return DB::transaction(function () use ($data) {
            $campaign = Campaign::findOrFail(data_get($data, 'id'));

            $campaign->update([
                'name' => data_get($data, 'name'),
                'content' => data_get($data, 'content'),
            ]);

            if ($companies = data_get($data, 'companies')) {
                $campaign->companies()->sync($companies);
            }

            if ($branches = data_get($data, 'branches')) {
                $campaign->branches()->sync($branches);
            }

            $campaign->triggers()->updateOrCreate(
                ['campaign_id' => $campaign->id],
                [
                    'trigger_type' => TriggerType::EVENT->value,
                    'event_type' => data_get($data, 'event_type'),
                    'hours_before' => data_get($data, 'hours_before'),
                    'hours_after' => data_get($data, 'hours_after'),
                ]
            );

            return $campaign->fresh();
        });
    }
}