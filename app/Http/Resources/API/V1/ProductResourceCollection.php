<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ProductResourceCollection extends ResourceCollection
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
     * Transform the resource collection into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return $this->collection->map(function ($product) use ($request) {
            return (new ProductResource($product))->menuId($this->menuId)->toArray($request);
        })->all();
    }
}