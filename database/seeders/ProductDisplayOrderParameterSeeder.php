<?php

namespace Database\Seeders;

use App\Models\Parameter;
use Illuminate\Database\Seeder;

class ProductDisplayOrderParameterSeeder extends Seeder
{
    public function run(): void
    {
        Parameter::firstOrCreate(
            ['name' => Parameter::PRODUCT_DISPLAY_ORDER_AUTO_APPLY],
            [
                'description' => 'Habilita la aplicación automática del display_order de productos a los menús de Café',
                'value_type' => 'boolean',
                'value' => '1',
                'active' => true,
            ]
        );
    }
}
