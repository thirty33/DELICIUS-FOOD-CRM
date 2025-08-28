<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchRuleCompany extends Model
{
    protected $fillable = [
        'dispatch_rule_id',
        'company_id',
    ];

    public function dispatchRule(): BelongsTo
    {
        return $this->belongsTo(DispatchRule::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
