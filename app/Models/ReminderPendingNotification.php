<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderPendingNotification extends Model
{
    protected $fillable = [
        'trigger_id',
        'conversation_id',
        'phone_number',
        'message_content',
        'menu_ids',
        'status',
    ];

    protected $casts = [
        'menu_ids' => 'array',
    ];

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(CampaignTrigger::class, 'trigger_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}