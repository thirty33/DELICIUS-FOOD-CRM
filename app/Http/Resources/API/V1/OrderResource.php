<?php

namespace App\Http\Resources\API\V1;

use App\Classes\DateTimeHelper;
use App\Classes\Menus\MenuHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Classes\PriceFormatter;
use Carbon\Carbon;

class OrderResource extends JsonResource
{

    protected $withMenu = false;

    public function withMenu($value = true)
    {
        $this->withMenu = $value;
        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {   

        $data = [
            'id' => $this->id,
            'total' => PriceFormatter::format($this->total),
            'total_with_tax' => PriceFormatter::format($this->total_with_tax),
            'status' => $this->status,
            'user_id' => $this->user_id,
            'price_list_min' => $this->price_list_min,
            'branch_id' => $this->branch_id,
            'dispatch_date' => DateTimeHelper::formatDateReadable($this->dispatch_date),
            'created_date' => DateTimeHelper::formatDateReadable($this->created_at),
            'alternative_address' => $this->alternative_address,
            'address' => $this->user->branch->address,
            'order_lines' => OrderLineResource::collection($this->whenLoaded('orderLines')),
        ];

        if ($this->withMenu) {
            $data['menu'] = new MenuResource($this->getCurrentMenu());
        }

        return $data;
    }

    protected function getCurrentMenu()
    {
        $carbonDate = Carbon::parse($this->dispatch_date)->format('Y-m-d');
        return MenuHelper::getMenu($carbonDate, $this->user)->first();
    }
}
