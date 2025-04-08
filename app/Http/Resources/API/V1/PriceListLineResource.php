<?php

namespace App\Http\Resources\API\V1;

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
            'unit_price' => '$'.number_format($this->unit_price / 100, 2, ',', '.'),
            'unit_price_with_tax' => '$'.number_format($unitPriceWithTax / 100, 2, ',', '.'),
            'unit_price_raw' => $this->unit_price / 100,
            'unit_price_with_tax_raw' => $unitPriceWithTax / 100,    
        ];
    }
}
