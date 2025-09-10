<?php

namespace App\Http\Resources\API\V1;

use App\Classes\DateTimeHelper;
use App\Classes\Menus\MenuHelper;
use App\Classes\PriceFormatter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'total_with_tax' => PriceFormatter::format($this->grand_total),
            'dispatch_cost' => PriceFormatter::format($this->dispatch_cost),
            'tax_amount' => PriceFormatter::format($this->tax_amount),
            'status' => $this->status,
            'user_id' => $this->user_id,
            'price_list_min' => $this->price_list_min,
            'branch_id' => $this->branch_id,
            'dispatch_date' => DateTimeHelper::formatDateReadable($this->dispatch_date),
            'created_date' => DateTimeHelper::formatDateReadable($this->created_at),
            'alternative_address' => $this->alternative_address,
            'address' => $this->user->branch->address,
            'order_lines' => OrderLineResource::collection($this->whenLoaded('orderLines')),
            'user_comment' => $this->user_comment,
            'user' => new SubordinateUserResource($this->whenLoaded('user')),
            'shipping_threshold' => $this->formatShippingThreshold($this->shipping_threshold_info),
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

    /**
     * Format shipping threshold information for API response
     */
    protected function formatShippingThreshold(array $thresholdInfo): array
    {
        if (! $thresholdInfo['has_better_rate']) {
            return [
                'has_better_rate' => false,
                'next_threshold_amount' => null,
                'next_threshold_cost' => null,
                'amount_to_reach' => null,
                'current_cost' => null,
                'savings' => null,
            ];
        }

        return [
            'has_better_rate' => true,
            'next_threshold_amount' => PriceFormatter::format($thresholdInfo['next_threshold_amount']),
            'next_threshold_cost' => PriceFormatter::format($thresholdInfo['next_threshold_cost']),
            'amount_to_reach' => PriceFormatter::format($thresholdInfo['amount_to_reach']),
            'current_cost' => PriceFormatter::format($thresholdInfo['current_cost']),
            'savings' => PriceFormatter::format($thresholdInfo['savings']),
        ];
    }
}
