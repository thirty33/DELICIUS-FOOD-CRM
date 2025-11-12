<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Seeder for Multi-Chunk PriceList Import Test
 *
 * Creates 150 products to test that a single price list
 * split across multiple chunks (chunk size = 100) imports correctly.
 *
 * Test Data:
 * - Price List: LISTA TEST MULTI-CHUNK (will be created by import)
 * - Products: 150 (TEST-MULTI-PRICE-001 to TEST-MULTI-PRICE-150)
 * - Expected: 2 chunks (100 + 50 lines)
 * - Category: 1
 */
class PriceListMultiChunkTestSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Category
        $category = Category::create([
            'name' => 'CATEGORIA TEST MULTI-CHUNK PRECIOS',
            'is_active' => true,
        ]);

        // 2. Create 150 Products
        for ($i = 1; $i <= 150; $i++) {
            $productCode = 'TEST-MULTI-PRICE-' . str_pad($i, 3, '0', STR_PAD_LEFT);

            Product::create([
                'code' => $productCode,
                'name' => "Producto Test Multi-Chunk Precio {$i}",
                'description' => "Producto de prueba para importación multi-chunk de lista de precios {$i}",
                'category_id' => $category->id,
                'measure_unit' => 'UND',
                'weight' => 0,
                'active' => true,
                'allow_sales_without_stock' => true,
            ]);
        }

        $this->command->info('✅ Multi-chunk price list test data seeded successfully!');
        $this->command->info("   - Category: {$category->name}");
        $this->command->info("   - Products: 150 (TEST-MULTI-PRICE-001 to TEST-MULTI-PRICE-150)");
        $this->command->info("   - Expected chunks: 2 (100 + 50 lines)");
    }
}
