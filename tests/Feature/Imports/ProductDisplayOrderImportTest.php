<?php

namespace Tests\Feature\Imports;

use App\Imports\ProductsImport;
use App\Models\Category;
use App\Models\ImportProcess;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * TDD Red Phase - Product display_order Import Tests
 *
 * These tests validate that the display_order field is correctly
 * imported from Excel files. They will FAIL until:
 * 1. 'orden' column is added to ProductColumnDefinition::COLUMNS and HEADING_MAP
 * 2. 'display_order' field is added to products table (migration)
 * 3. 'display_order' is added to Product::$fillable
 * 4. ProductsImport processes the 'orden' column
 *
 * Fixtures used (17 columns, "Orden" as last column after "Codigo de Facturacion"):
 * - test_product_display_order.xlsx: TEST-DO-001 with Orden = 5
 * - test_product_display_order_update.xlsx: TEST-DO-001 with Orden = 10
 * - test_product_display_order_string.xlsx: TEST-DO-002 with Orden = "3" (text)
 */
class ProductDisplayOrderImportTest extends TestCase
{
    use ConfiguresImportTests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureImportTest();

        Category::create([
            'name' => 'MINI ENSALADAS',
            'code' => 'ENS',
            'active' => true,
        ]);
    }

    public function test_imports_product_with_display_order(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $testFile = base_path('tests/Fixtures/test_product_display_order.xlsx');
        $this->assertFileExists($testFile);

        Excel::import(
            new ProductsImport($importProcess->id),
            $testFile
        );

        $importProcess->refresh();
        $this->assertEquals(ImportProcess::STATUS_PROCESSED, $importProcess->status);

        $product = Product::where('code', 'TEST-DO-001')->first();
        $this->assertNotNull($product, 'Product TEST-DO-001 should exist');
        $this->assertEquals(5, $product->display_order, 'Display order should be imported as 5');
    }

    public function test_imports_product_without_display_order_uses_default(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Uses existing fixture without "Orden" column
        $testFile = base_path('tests/Fixtures/test_single_product.xlsx');
        $this->assertFileExists($testFile);

        Excel::import(
            new ProductsImport($importProcess->id),
            $testFile
        );

        $importProcess->refresh();
        $this->assertEquals(ImportProcess::STATUS_PROCESSED, $importProcess->status);

        $product = Product::where('code', 'TEST-PROD-001')->first();
        $this->assertNotNull($product, 'Product should exist');
        $this->assertEquals(9999, $product->display_order, 'Display order should default to 9999');
    }

    public function test_updates_display_order_on_reimport(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        // First import with display_order = 5
        $importProcess1 = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new ProductsImport($importProcess1->id),
            base_path('tests/Fixtures/test_product_display_order.xlsx')
        );

        $product = Product::where('code', 'TEST-DO-001')->first();
        $this->assertNotNull($product);
        $this->assertEquals(5, $product->display_order, 'Initial display order should be 5');

        // Re-import with display_order = 10
        $importProcess2 = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new ProductsImport($importProcess2->id),
            base_path('tests/Fixtures/test_product_display_order_update.xlsx')
        );

        $product->refresh();
        $this->assertEquals(1, Product::where('code', 'TEST-DO-001')->count(), 'Should not duplicate product');
        $this->assertEquals(10, $product->display_order, 'Display order should be updated to 10');
    }

    public function test_imports_display_order_as_integer(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $testFile = base_path('tests/Fixtures/test_product_display_order_string.xlsx');
        $this->assertFileExists($testFile);

        Excel::import(
            new ProductsImport($importProcess->id),
            $testFile
        );

        $product = Product::where('code', 'TEST-DO-002')->first();
        $this->assertNotNull($product, 'Product TEST-DO-002 should exist');
        $this->assertIsInt($product->display_order, 'Display order should be cast to integer');
        $this->assertEquals(3, $product->display_order, 'Display order should be 3');
    }
}
