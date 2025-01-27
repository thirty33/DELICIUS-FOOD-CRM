<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryMenuResource extends JsonResource
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
            'order' => $this->display_order,
            'show_all_products' => $this->show_all_products,
            'category_id' => $this->category_id,
            'menu_id' => $this->menu_id,
            'category' => new CategoryResource($this->whenLoaded('category'), $this->show_all_products, $this->menu_id),
            'menu' => new MenuResource($this->whenLoaded('menu')),
            'products' => $this->show_all_products ? [] : ProductResource::collection($this->whenLoaded('products'))
        ];
    }
}
