<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DispatchRule extends Model
{
    protected $fillable = [
        'name',
        'priority',
        'active',
        'all_companies',
        'all_branches',
    ];

    protected $casts = [
        'active' => 'boolean',
        'all_companies' => 'boolean',
        'all_branches' => 'boolean',
        'priority' => 'integer',
    ];

    public function ranges(): HasMany
    {
        return $this->hasMany(DispatchRuleRange::class)->orderBy('min_amount');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'dispatch_rule_companies')
            ->withTimestamps();
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'dispatch_rule_branches')
            ->withTimestamps();
    }
}
