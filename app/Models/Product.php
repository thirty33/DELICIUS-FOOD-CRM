<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'image',
        'category_id',
        'code',
        'active',
        'measure_unit',
        'price_list',
        'stock',
        'weight',
        'allow_sales_without_stock',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_list' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
