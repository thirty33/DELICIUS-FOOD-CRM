<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\ResourceCollection;

class OrderResourceCollection extends ResourceCollection
{
    protected $withMenu = false;

    public function withMenu($value = true)
    {
        $this->withMenu = $value;
        return $this;
    }

    public function toArray($request)
    {
        $originalArray = $this->resource->toArray();
        
        $originalArray['data'] = $this->collection->map(function (OrderResource $resource) use ($request) {
            return $resource->withMenu($this->withMenu)->toArray($request);
        })->all();

        return $originalArray;
    }
}
