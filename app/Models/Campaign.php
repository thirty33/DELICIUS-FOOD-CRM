<?php

namespace App\Models;

use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = [
        'name',
        'type',
        'channel',
        'status',
        'subject',
        'content',
        'template_name',
        'created_by',
    ];

    protected $casts = [
        'type' => CampaignType::class,
        'channel' => CampaignChannel::class,
        'status' => CampaignStatus::class,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function triggers(): HasMany
    {
        return $this->hasMany(CampaignTrigger::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(CampaignExecution::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'campaign_company');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'campaign_branch');
    }

    public function latestExecution(): HasMany
    {
        return $this->hasMany(CampaignExecution::class)->latestOfMany();
    }
}