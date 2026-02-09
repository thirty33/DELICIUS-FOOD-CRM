<?php

namespace App\Actions\Reminders;

use App\Actions\Contracts\UpdateAction;
use App\Models\CampaignTrigger;

final class UpdateTriggerLastExecutedAction implements UpdateAction
{
    public static function execute(array $data = []): CampaignTrigger
    {
        $trigger = CampaignTrigger::findOrFail(data_get($data, 'trigger_id'));

        $trigger->update(['last_executed_at' => now()]);

        return $trigger;
    }
}