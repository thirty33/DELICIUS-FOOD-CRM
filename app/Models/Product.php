<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

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
        'title_product',
        'original_filename',
        'cloudfront_signed_url',
        'signed_url_expiration',
        'is_null_product'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_list' => 'decimal:2',
        'is_null_product' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function CategoryMenus(): BelongsToMany
    {
        return $this->belongsToMany(CategoryMenu::class, 'category_menu_product', 'product_id', 'category_menu_id');
    }

    public function titleProduct(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => $attributes['code'] . ' - ' . $attributes['name']
        );
    }

    public function priceListLines(): HasMany 
    {
        return $this->hasMany(PriceListLine::class, 'product_id', 'id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

}
