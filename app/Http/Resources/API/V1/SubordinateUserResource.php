<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubordinateUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'nickname' => $this->nickname,
            'email' => $this->email,
            'branch_name' => $this->branch ? $this->branch->fantasy_name : null,
            'branch_address' => $this->branch ? $this->branch->address : null,
        ];
    }
}