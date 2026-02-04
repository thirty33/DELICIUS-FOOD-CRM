<?php

namespace App\Models;

use App\Enums\CampaignMessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CampaignMessage extends Model
{
    protected $fillable = [
        'execution_id',
        'recipient_type',
        'recipient_id',
        'recipient_address',
        'status',
        'external_id',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'status' => CampaignMessageStatus::class,
        'sent_at' => 'datetime',
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(CampaignExecution::class, 'execution_id');
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }
}