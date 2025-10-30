<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'address',
        'shipping_address',
        'contact_name',
        'contact_last_name',
        'contact_phone_number',
        'branch_code',
        'fantasy_name',
        'min_price_order'
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function dispatchRules(): BelongsToMany
    {
        return $this->belongsToMany(DispatchRule::class, 'dispatch_rule_branches')
            ->withTimestamps();
    }
}
