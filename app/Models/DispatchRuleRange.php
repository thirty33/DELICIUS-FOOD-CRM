<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchRuleRange extends Model
{
    protected $fillable = [
        'dispatch_rule_id',
        'min_amount',
        'max_amount',
        'dispatch_cost',
    ];

    protected $casts = [
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'dispatch_cost' => 'integer',
    ];

    public function dispatchRule(): BelongsTo
    {
        return $this->belongsTo(DispatchRule::class);
    }
}
