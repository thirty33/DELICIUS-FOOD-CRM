<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'active' => $this->active,
            'title' => $this->title,
            'description' => $this->description,
            'publication_date' => $this->publication_date,
            'has_order' => (int) ($this->has_order ?? 0),
            'order_id' => $this->order_id ?? null,
            'order_status' => $this->order_status ?? null,
        ];
        
        return $data;
    }
}
