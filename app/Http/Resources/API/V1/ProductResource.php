<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'price' => '$'.number_format($this->price / 100, 2, ',', '.'), 
            'image' => env('APP_URL').Storage::url($this->image),
            'category_id' => $this->category_id,
            'code' => $this->code,
            'active' => $this->active,
            'measure_unit' => $this->measure_unit,
            'price_list' => $this->price_list,
            'stock' => $this->stock,
            'weight' => $this->weight,
            'allow_sales_without_stock' => $this->allow_sales_without_stock,
        ];
    }
}