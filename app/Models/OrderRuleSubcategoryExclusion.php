<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRuleSubcategoryExclusion extends Model
{
    protected $fillable = [
        'order_rule_id',
        'subcategory_id',
        'excluded_subcategory_id',
    ];

    protected $casts = [
        'order_rule_id' => 'integer',
        'subcategory_id' => 'integer',
        'excluded_subcategory_id' => 'integer',
    ];

    public function orderRule(): BelongsTo
    {
        return $this->belongsTo(OrderRule::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class, 'subcategory_id');
    }

    public function excludedSubcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class, 'excluded_subcategory_id');
    }
}
