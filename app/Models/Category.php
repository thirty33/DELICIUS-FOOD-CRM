<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'preparation_days',
        'preparation_hours',
        'preparation_minutes',
        'is_active',
        'order_start_time',
        'order_end_time',
        'is_active_monday',
        'is_active_tuesday',
        'is_active_wednesday',
        'is_active_thursday',
        'is_active_friday',
        'is_active_saturday',
        'is_active_sunday',
        'role_id',
        'permissions_id'
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class);
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permissions_id', 'id');
    }

    public function categoryLines(): HasMany
    {
        return $this->hasMany(CategoryLine::class);
    }


}
