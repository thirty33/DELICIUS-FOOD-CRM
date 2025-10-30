<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseProduct extends Model
{
    protected $table = 'warehouse_product';

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'stock',
        'unit_of_measure',
    ];

    protected $casts = [
        'stock' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
