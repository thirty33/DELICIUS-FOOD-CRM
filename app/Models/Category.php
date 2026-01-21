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
        'is_dynamic',
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
        'permissions_id',
        'subcategory'
    ];

    protected $casts = [
        'is_dynamic' => 'boolean',
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

    public function subcategories(): BelongsToMany
    {
        return $this->belongsToMany(Subcategory::class, 'category_subcategory')->using(CategorySubcategory::class);
    }
    
    public function hasAnySubcategories(array $subcategories): bool
    {
        return $this->subcategories->whereIn('name', $subcategories)->isNotEmpty();
    }

    public function hasSubcategories(): bool
    {
        return $this->subcategories->isNotEmpty();
    }

    public function categoryUserLines(): HasMany
    {
        return $this->hasMany(CategoryUserLine::class);
    }

    /**
     * Get the category groups that belong to this category.
     *
     * @return BelongsToMany
     */
    public function categoryGroups(): BelongsToMany
    {
        return $this->belongsToMany(CategoryGroup::class, 'category_category_group')
            ->withTimestamps();
    }
    
}
