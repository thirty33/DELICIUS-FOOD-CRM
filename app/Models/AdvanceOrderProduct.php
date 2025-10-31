<?php

namespace App\Models;

use App\Repositories\WarehouseRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceOrderProduct extends Model
{
    protected $fillable = [
        'advance_order_id',
        'product_id',
        'quantity',
        'ordered_quantity',
        'ordered_quantity_new',
        'total_to_produce',
    ];

    public function advanceOrder(): BelongsTo
    {
        return $this->belongsTo(AdvanceOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculate total to produce based on formula:
     * IF quantity > 0 THEN: MAX(0, quantity - initial_inventory)
     * ELSE: MAX(0, ordered_quantity_new - initial_inventory)
     */
    public function calculateTotalToProduce(): int
    {
        $warehouseRepository = new WarehouseRepository();
        $defaultWarehouse = $warehouseRepository->getDefaultWarehouse();

        if (!$defaultWarehouse) {
            return 0;
        }

        $initialInventory = $warehouseRepository->getProductStockInWarehouse(
            $this->product_id,
            $defaultWarehouse->id
        );

        if ($this->quantity > 0) {
            return max(0, $this->quantity - $initialInventory);
        } else {
            return max(0, $this->ordered_quantity_new - $initialInventory);
        }
    }
}
