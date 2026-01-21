<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ProductResource extends JsonResource
{
    protected ?int $menuId = null;

    /**
     * Set the menu ID for category line formatting.
     *
     * @param int|null $menuId
     * @return $this
     */
    public function menuId(?int $menuId): self
    {
        $this->menuId = $menuId;
        return $this;
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
            'price' => '$'.number_format($this->price / 100, 2, ',', '.'),
            'image' => $this->getImageUrl(),
            'category_id' => $this->category_id,
            'code' => $this->code,
            'active' => $this->active,
            'measure_unit' => $this->measure_unit,
            'price_list' => $this->price_list,
            'stock' => $this->stock,
            'weight' => $this->weight,
            'allow_sales_without_stock' => $this->allow_sales_without_stock,
            'price_list_lines' => PriceListLineResource::collection($this->whenLoaded('priceListLines')),
            'ingredients' => IngredientResource::collection($this->whenLoaded('ingredients')),
            'category' => new CategoryResource($this->whenLoaded('category'), false, $this->menuId),
        ];
    }

    /**
     * Get the product image URL
     * 
     * @return string|null
     */
    private function getImageUrl(): ?string
    {
        if (!$this->image) {
            return null;
        }

        if ($this->cloudfront_signed_url && $this->signed_url_expiration && Carbon::parse($this->signed_url_expiration)->isFuture()) {
            return $this->cloudfront_signed_url;
        }

        return null;
    }
}
