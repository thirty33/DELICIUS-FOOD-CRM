<?php

namespace App\Http\Resources\API\V1;

use App\Classes\PriceFormatter;
use App\Models\Parameter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListLineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $taxValue = Parameter::getValue(Parameter::TAX_VALUE, 0);
        
        // Calcular el precio con impuesto
        $unitPriceWithTax = $this->unit_price * (1 + $taxValue);

        return [
            'id' => $this->id,
            'unit_price' => PriceFormatter::formatRounded($this->unit_price),
            'unit_price_with_tax' => PriceFormatter::formatRounded($unitPriceWithTax),
            'unit_price_raw' => round($this->unit_price / 100),
            'unit_price_with_tax_raw' => round($unitPriceWithTax / 100),
        ];
    }
}
