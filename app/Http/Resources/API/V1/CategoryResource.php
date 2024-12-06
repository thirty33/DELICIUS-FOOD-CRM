<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{

    protected $showAllProducts;

    public function __construct($resource, $showAllProducts = false)
    {
        parent::__construct($resource);
        $this->showAllProducts = $showAllProducts;
    }

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
            'products' => $this->showAllProducts ? ProductResource::collection($this->whenLoaded('products')) : []
        ];
    }
}
