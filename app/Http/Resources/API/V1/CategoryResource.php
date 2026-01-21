<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{

    protected $showAllProducts;
    protected $menuId;

    public function __construct($resource, $showAllProducts = false, $menuId = null)
    {
        parent::__construct($resource);
        $this->showAllProducts = $showAllProducts;
        $this->menuId = $menuId;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        
        $categoryLineCollection = (new CategoryLineResourceCollection($this->whenLoaded('categoryLines')))->menuId($this->menuId);

        $categoryUserLineCollection = (new CategoryLineResourceCollection($this->whenLoaded('categoryUserLines')))->menuId($this->menuId);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_dynamic' => $this->is_dynamic ?? false,
            'products' => $this->showAllProducts ? (new ProductResourceCollection($this->whenLoaded('products')))->menuId($this->menuId) : [],
            // 'category_lines' => CategoryLineResource::collection($this->whenLoaded('categoryLines')),
            'category_lines' => $categoryLineCollection,
            'category_user_lines' => $categoryUserLineCollection,
            'subcategories' => SubcategoriesResource::collection($this->whenLoaded('subcategories'))
        ];
    }
}
