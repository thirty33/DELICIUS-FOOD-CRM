<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceOrderOrder extends Model
{
    protected $table = 'advance_order_orders';

    protected $fillable = [
        'advance_order_id',
        'order_id',
        'order_number',
        'order_dispatch_date',
        'order_status',
        'order_user_id',
        'order_user_nickname',
        'order_total',
    ];

    protected $casts = [
        'order_dispatch_date' => 'date',
        'order_total' => 'integer',
    ];

    /**
     * Get the advance order that owns this association.
     */
    public function advanceOrder(): BelongsTo
    {
        return $this->belongsTo(AdvanceOrder::class, 'advance_order_id');
    }

    /**
     * Get the order that is associated with the advance order.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the user who made the order.
     */
    public function orderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'order_user_id');
    }
}
