<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'rule_type',
        'role_id',
        'permission_id',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function subcategoryExclusions(): HasMany
    {
        return $this->hasMany(OrderRuleSubcategoryExclusion::class);
    }

    public function subcategoryLimits(): HasMany
    {
        return $this->hasMany(OrderRuleSubcategoryLimit::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'order_rule_companies');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForRoleAndPermission($query, $roleId, $permissionId)
    {
        return $query->where('role_id', $roleId)
            ->where('permission_id', $permissionId);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }
}
