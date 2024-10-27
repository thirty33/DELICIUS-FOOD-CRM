<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'weekday',
        'preparation_days',
        'maximum_order_time',
        'active'
    ];

    protected $casts = [
        'active' => 'boolean',
        'maximum_order_time' => 'datetime:H:i',
        'preparation_days' => 'integer'
    ];

    /**
     * Get the category that owns the category line.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }
}
