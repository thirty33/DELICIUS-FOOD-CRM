<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderNotifiedMenu extends Model
{
    protected $fillable = [
        'trigger_id',
        'menu_id',
        'phone_number',
        'conversation_id',
        'status',
        'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(CampaignTrigger::class, 'trigger_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}