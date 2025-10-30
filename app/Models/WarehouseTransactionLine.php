<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTransactionLine extends Model
{
    protected $fillable = [
        'warehouse_transaction_id',
        'product_id',
        'stock_before',
        'stock_after',
        'difference',
        'unit_of_measure',
    ];

    protected $casts = [
        'stock_before' => 'integer',
        'stock_after' => 'integer',
        'difference' => 'integer',
    ];

    public function warehouseTransaction(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
