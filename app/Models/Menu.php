<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Menu extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'start_date',
        'end_date',
        'active',
        'title',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'active' => 'boolean',
    ];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * Scope a query to only include active menus.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope a query to only include menus for a specific date.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \DateTime  $date
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('start_date', '<=', $date)
                     ->where('end_date', '>=', $date);
    }

    /**
     * Check if the menu's date range overlaps with existing active menus.
     *
     * @return bool
     */
    public function hasOverlap()
    {
        return static::where(function (Builder $query) {
            $query->where(function (Builder $q) {
                $q->where('start_date', '<=', $this->start_date)
                  ->where('end_date', '>=', $this->start_date);
            })->orWhere(function (Builder $q) {
                $q->where('start_date', '<=', $this->end_date)
                  ->where('end_date', '>=', $this->end_date);
            })->orWhere(function (Builder $q) {
                $q->where('start_date', '>=', $this->start_date)
                  ->where('end_date', '<=', $this->end_date);
            });
        })
        ->where('id', '!=', $this->id)
        ->where('active', true)
        ->exists();
    }
}
