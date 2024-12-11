<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;
use App\Models\Ingredient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Faker\Factory;

class ProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Factory::create();

        $categories = Category::all();

        $imageName = config('app.TEST_IMAGE_PATH');

        foreach ($categories as $category) {
            for ($i = 1; $i <= 20; $i++) {

                $product  = Product::firstOrCreate([
                    'name' => $category->name . ' Product ' . $i,
                ], [
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

                $limit = rand(3, 8);

                for ($j = 1; $j <= $limit; $j++) {

                    $descriptive_text = implode(' ', [
                        $faker->randomElement(['Fresco', 'Orgánico', 'Natural', 'Auténtico', 'Delicioso']),
                        $faker->randomElement(['Ingrediente', 'Producto', 'Extracto', 'Condimento', 'Sabor']),
                        $faker->randomElement(['de', 'con', 'estilo', 'selección', 'método']),
                        $faker->randomElement(['Oliva', 'Romero', 'Tomillo', 'Albahaca', 'Pimienta', 'Cilantro', 'Jengibre']),
                        $faker->randomElement(['Premium', 'Selecto', 'Especial', 'Gourmet', 'Artesanal'])
                    ]);

                    Ingredient::firstOrCreate([
                        'descriptive_text' => $descriptive_text,
                        'product_id' => $product->id
                    ]);
                }
            }
        }
    }
}
