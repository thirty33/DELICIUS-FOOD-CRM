<?php

namespace App\Http\Resources\API\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\Weekday;
use App\Classes\DateTimeHelper;
use Carbon\Carbon;
use app\Models\Menu;

class CategoryLineResource extends JsonResource
{

    protected $menuId;

    public function menuId($value)
    {
        $this->menuId = $value;
        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $weekdayInSpanish = Weekday::from($this->weekday)->toSpanish();
        $menu = Menu::find($this->menuId);
        $publicationDate = Carbon::parse($menu->publication_date);

        $formattedText = DateTimeHelper::formatMaximumOrderTime(
            Carbon::parse($this->maximum_order_time),
            $this->preparation_days, 
            $publicationDate 
        );

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'weekday' => $weekdayInSpanish,
            'preparation_days' => $this->preparation_days,
            'maximum_order_time' => $formattedText,
            'active' => $this->active,
        ];
    }
}
