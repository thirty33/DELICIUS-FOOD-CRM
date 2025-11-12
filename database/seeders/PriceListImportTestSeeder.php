<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Seeder for PriceListImport Test Data
 *
 * Creates minimal data needed to test a single price list import
 * with 4 products.
 *
 * Test Data:
 * - Price List: LISTA TEST (will be created by import)
 * - Products: 4 (TEST-PRICE-001 to TEST-PRICE-004)
 * - Category: 1
 */
class PriceListImportTestSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Category
        $category = Category::create([
            'name' => 'CATEGORIA TEST PRECIOS',
            'is_active' => true,
        ]);

        // 2. Create 4 Products
        $products = [
            [
                'code' => 'TEST-PRICE-001',
                'name' => 'Producto Test Precio 1',
                'description' => 'Producto de prueba para importación de lista de precios 1',
            ],
            [
                'code' => 'TEST-PRICE-002',
                'name' => 'Producto Test Precio 2',
                'description' => 'Producto de prueba para importación de lista de precios 2',
            ],
            [
                'code' => 'TEST-PRICE-003',
                'name' => 'Producto Test Precio 3',
                'description' => 'Producto de prueba para importación de lista de precios 3',
            ],
            [
                'code' => 'TEST-PRICE-004',
                'name' => 'Producto Test Precio 4',
                'description' => 'Producto de prueba para importación de lista de precios 4',
            ],
        ];

        foreach ($products as $productData) {
            Product::create([
                'code' => $productData['code'],
                'name' => $productData['name'],
                'description' => $productData['description'],
                'category_id' => $category->id,
                'measure_unit' => 'UND',
                'weight' => 0,
                'active' => true,
                'allow_sales_without_stock' => true,
            ]);
        }

        $this->command->info('✅ Price list test data seeded successfully!');
        $this->command->info("   - Category: {$category->name}");
        $this->command->info("   - Products: " . count($products));
    }
}
