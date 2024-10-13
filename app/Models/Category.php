<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class);
    }
}
