<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function CategoryMenus(): BelongsToMany
    {
        return $this->belongsToMany(CategoryMenu::class, 'category_menu_product', 'product_id', 'category_menu_id');
    }

}
