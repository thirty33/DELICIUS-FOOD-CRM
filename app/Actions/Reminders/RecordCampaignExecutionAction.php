<?php

namespace App\Actions\Reminders;

use App\Actions\Contracts\CreateAction;
use App\Enums\CampaignExecutionStatus;
use App\Models\CampaignExecution;

final class RecordCampaignExecutionAction implements CreateAction
{
    public static function execute(array $data = []): CampaignExecution
    {
        return CampaignExecution::create([
            'campaign_id' => data_get($data, 'campaign_id'),
            'trigger_id' => data_get($data, 'trigger_id'),
            'executed_at' => now(),
            'triggered_by' => 'system',
            'total_recipients' => data_get($data, 'total_recipients', 0),
            'sent_count' => data_get($data, 'sent_count', 0),
            'failed_count' => data_get($data, 'failed_count', 0),
            'status' => data_get($data, 'status', CampaignExecutionStatus::COMPLETED->value),
            'completed_at' => now(),
            'error_message' => data_get($data, 'error_message'),
        ]);
    }
}
