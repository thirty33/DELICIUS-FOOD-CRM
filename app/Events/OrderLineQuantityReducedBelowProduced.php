<?php

namespace App\Events;

use App\Models\OrderLine;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when an order line quantity is reduced below what was already produced.
 *
 * This event is used to create a surplus warehouse transaction, adding the
 * extra produced units back to inventory.
 *
 * Example:
 * - Order line had qty=10, produced=10, user reduces qty to 8
 * - Surplus = 10 - 8 = 2 units
 * - Event is dispatched with surplus=2
 * - Listener creates warehouse transaction to add 2 units to stock
 */
class OrderLineQuantityReducedBelowProduced
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public OrderLine $orderLine;
    public int $oldQuantity;
    public int $newQuantity;
    public int $producedQuantity;
    public int $surplus;
    public ?int $userId;

    // Data captured from relationships BEFORE OrderLine might be deleted
    // This is needed because the Job runs asynchronously and the OrderLine
    // may no longer exist when the Job executes
    public int $orderId;
    public int $productId;
    public string $productName;
    public string $measureUnit;

    /**
     * Create a new event instance.
     */
    public function __construct(
        OrderLine $orderLine,
        int $oldQuantity,
        int $newQuantity,
        int $producedQuantity,
        int $surplus,
        ?int $userId = null
    ) {
        $this->orderLine = $orderLine;
        $this->oldQuantity = $oldQuantity;
        $this->newQuantity = $newQuantity;
        $this->producedQuantity = $producedQuantity;
        $this->surplus = $surplus;
        $this->userId = $userId;

        // Capture data NOW while OrderLine still exists
        $this->orderId = $orderLine->order_id;
        $this->productId = $orderLine->product_id;
        $this->productName = $orderLine->product->name ?? 'Producto ID ' . $orderLine->product_id;
        $this->measureUnit = $orderLine->product->measure_unit ?? 'UND';
    }
}
