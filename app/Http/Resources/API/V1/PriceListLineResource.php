<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListLineResource extends JsonResource
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
            'unit_price' => '$'.number_format($this->unit_price / 100, 2, ',', '.'),
            // 'product' => new ProductResource($this->whenLoaded('product'))        
        ];
    }
}
