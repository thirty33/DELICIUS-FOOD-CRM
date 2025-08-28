<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchRuleBranch extends Model
{
    protected $fillable = [
        'dispatch_rule_id',
        'branch_id',
    ];

    public function dispatchRule(): BelongsTo
    {
        return $this->belongsTo(DispatchRule::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
