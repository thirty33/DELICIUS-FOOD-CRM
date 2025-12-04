<?php

namespace App\Models;

use App\Repositories\WarehouseRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($product) {
            $warehouseRepository = new WarehouseRepository();
            $warehouseRepository->associateProductToDefaultWarehouse(
                $product,
                0, // Initial stock = 0
                'UND' // Default unit of measure
            );
        });
    }

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

    /**
     * Get warehouses where this product is stored
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_product')
            ->withPivot('stock', 'unit_of_measure')
            ->withTimestamps();
    }

    /**
     * Get the stock in a specific warehouse
     */
    public function getStockInWarehouse(int $warehouseId): int
    {
        $warehouse = $this->warehouses()->where('warehouse_id', $warehouseId)->first();
        return $warehouse ? $warehouse->pivot->stock : 0;
    }

    /**
     * Get the stock in the default warehouse
     */
    public function getDefaultWarehouseStock(): int
    {
        $defaultWarehouse = Warehouse::where('is_default', true)->first();
        return $defaultWarehouse ? $this->getStockInWarehouse($defaultWarehouse->id) : 0;
    }

    /**
     * Get total stock across all warehouses
     */
    public function getTotalStock(): int
    {
        return $this->warehouses()->sum('warehouse_product.stock');
    }

    public function productionAreas(): BelongsToMany
    {
        return $this->belongsToMany(ProductionArea::class, 'production_area_product');
    }

    /**
     * Get the nutritional information for this product (one-to-one)
     */
    public function nutritionalInformation(): HasOne
    {
        return $this->hasOne(NutritionalInformation::class);
    }

    /**
     * Get the plated dish for this product (one-to-one)
     */
    public function platedDish(): HasOne
    {
        return $this->hasOne(PlatedDish::class);
    }

}
