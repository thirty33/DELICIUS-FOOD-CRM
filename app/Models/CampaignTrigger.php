<?php

namespace App\Models;

use App\Enums\CampaignEventType;
use App\Enums\TriggerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignTrigger extends Model
{
    protected $fillable = [
        'campaign_id',
        'trigger_type',
        'event_type',
        'hours_before',
        'hours_after',
        'is_active',
        'last_executed_at',
    ];

    protected $casts = [
        'trigger_type' => TriggerType::class,
        'event_type' => CampaignEventType::class,
        'hours_before' => 'integer',
        'hours_after' => 'integer',
        'is_active' => 'boolean',
        'last_executed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(CampaignExecution::class, 'trigger_id');
    }
}