<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Classes\PriceFormatter;

class OrderResource extends JsonResource
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
            'total' => PriceFormatter::format($this->total),
            'status' => $this->status,
            'user_id' => $this->user_id,
            'price_list_min' => $this->price_list_min,
            'branch_id' => $this->branch_id,
            'dispatch_date' => $this->dispatch_date,
            'alternative_address' => $this->alternative_address,
            'order_lines' => OrderLineResource::collection($this->whenLoaded('orderLines')),
        ];
    }
}
