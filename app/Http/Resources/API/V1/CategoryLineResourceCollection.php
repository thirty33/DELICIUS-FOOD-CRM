<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class CategoryLineResourceCollection extends ResourceCollection
{
    protected $menuId;

    public function menuId($value)
    {
        $this->menuId = $value;
        return $this;
    }

    public function toArray($request)
    {
        return $this->collection->map(function (CategoryLineResource $resource) use ($request) {
            return $resource->menuId($this->menuId)->toArray($request);
        })->all();
    }
}