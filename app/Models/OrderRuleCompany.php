<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRuleCompany extends Model
{
    protected $fillable = [
        'order_rule_id',
        'company_id',
    ];

    protected $casts = [
        'order_rule_id' => 'integer',
        'company_id' => 'integer',
    ];

    public function orderRule(): BelongsTo
    {
        return $this->belongsTo(OrderRule::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
