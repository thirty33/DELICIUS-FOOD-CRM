<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Classes\PriceFormatter;

class OrderLineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'unit_price' => PriceFormatter::format($this->unit_price),
            'unit_price_with_tax' => PriceFormatter::format($this->unit_price_with_tax),
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'total_price' => PriceFormatter::format($this->total_price),
            'total_price_with_tax' => PriceFormatter::format($this->quantity * $this->unit_price_with_tax),
            'product' => new ProductResource($this->whenLoaded('product')),
            'partially_scheduled' => $this->partially_scheduled ? true : false,
        ];
    }
}
