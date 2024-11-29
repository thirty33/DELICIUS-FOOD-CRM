<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = Category::all();

        $imageName = '01JB9F8KS2TATVDHB5TFPT9Q2V.jpg';
        
        foreach ($categories as $category) {
            for ($i = 1; $i <= 20; $i++) {
                Product::create([
                    'name' => $category->name . ' Product ' . $i,
                    'description' => 'Description for ' . $category->name . ' Product ' . $i,
                    'price' => rand(1000, 10000), // Precio en centavos
                    'image' => $imageName, // Ruta de la imagen por defecto
                    'category_id' => $category->id,
                    'code' => strtoupper($category->name) . $i,
                    'active' => true,
                    'measure_unit' => 'unit',
                    'price_list' => rand(1000, 10000), // Precio de lista en centavos
                    'stock' => rand(0, 100),
                    'weight' => rand(100, 1000), // Peso en gramos
                    'allow_sales_without_stock' => false,
                ]);
            }
        }
    }
}