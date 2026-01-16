<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class SubordinateUserResourceCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->resource->toArray();
    }
}
