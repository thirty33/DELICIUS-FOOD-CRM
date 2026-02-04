<?php

namespace App\Models;

use App\Enums\CampaignExecutionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignExecution extends Model
{
    protected $fillable = [
        'campaign_id',
        'trigger_id',
        'executed_at',
        'triggered_by',
        'total_recipients',
        'sent_count',
        'failed_count',
        'status',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'status' => CampaignExecutionStatus::class,
        'executed_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(CampaignTrigger::class, 'trigger_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class, 'execution_id');
    }
}