<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceOrderOrderLine extends Model
{
    protected $table = 'advance_order_order_lines';

    protected $fillable = [
        'advance_order_id',
        'order_line_id',
        'product_id',
        'order_id',
        'quantity_covered',
        'product_name',
        'product_code',
        'order_dispatch_date',
        'order_number',
        'order_line_unit_price',
        'order_line_total_price',
    ];

    protected $casts = [
        'quantity_covered' => 'integer',
        'order_dispatch_date' => 'date',
        'order_line_unit_price' => 'integer',
        'order_line_total_price' => 'integer',
    ];

    /**
     * Get the advance order that owns this association.
     */
    public function advanceOrder(): BelongsTo
    {
        return $this->belongsTo(AdvanceOrder::class, 'advance_order_id');
    }

    /**
     * Get the order line that is associated with the advance order.
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    /**
     * Get the product associated with this order line.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Get the order that this line belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
