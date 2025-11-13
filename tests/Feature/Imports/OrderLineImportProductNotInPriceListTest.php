<?php

namespace Tests\Feature\Imports;

use App\Imports\OrderLinesImport;
use App\Models\ImportProcess;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Category;
use App\Enums\OrderStatus;
use Database\Seeders\OrderLinesImportTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * TDD Test - Product Not In Price List During Import
 *
 * PRODUCTION BUG:
 * - Product: "ACM - SIN ENTRADA" (ID: 1115, Code: ACM00000059)
 * - 12 orders on 14/11/2025 with this product have unit_price = NULL
 * - When creating advance orders, fails with: "Column 'order_line_unit_price' cannot be null"
 *
 * EXPECTED BEHAVIOR (TDD):
 * - When product NOT in price list, unit_price should be 0 (not NULL)
 * - unit_price_with_tax should be 0 (not NULL)
 */
class OrderLineImportProductNotInPriceListTest extends TestCase
{
    use RefreshDatabase;
    use ConfiguresImportTests;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment for imports (from trait)
        $this->configureImportTest();

        // Use same seeder as OrderLinesImportPilotTest
        $this->seed(OrderLinesImportTestSeeder::class);

        // Add product that is NOT in price list
        $this->createProductNotInPriceList();
    }

    protected function createProductNotInPriceList(): void
    {
        $category = Category::where('name', 'ACOMPAÑAMIENTOS')->first();

        // Create product WITHOUT adding to price list
        // This simulates production: "ACM - SIN ENTRADA"
        Product::create([
            'code' => 'ACM00000059',
            'name' => 'ACM - SIN ENTRADA',
            'description' => 'Producto sin entrada',
            'category_id' => $category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'active' => true,
            'allow_sales_without_stock' => true,
        ]);

        // IMPORTANT: We do NOT create PriceListLine for this product
    }

    /**
     * TDD Test - Product not in price list should have unit_price = 0 (not NULL)
     *
     * This test will FAIL initially because:
     * - OrderLine::calculateUnitPrice() returns NULL when product not in price list
     * - OrderLine model sets unit_price = NULL instead of 0
     */
    public function test_import_product_not_in_price_list_sets_unit_price_to_zero(): void
    {
        // Mock S3 storage (same as OrderLinesImportPilotTest)
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Arrange: Verify initial state
        $this->assertEquals(0, Order::count(), 'Should start with 0 orders');
        $this->assertEquals(0, OrderLine::count(), 'Should start with 0 order lines');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Get test Excel file (same template as OrderLinesImportPilotTest)
        $testFile = base_path('tests/Fixtures/test_single_order.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        // Modify Excel to use product NOT in price list
        $df = \PhpOffice\PhpSpreadsheet\IOFactory::load($testFile);
        $sheet = $df->getActiveSheet();

        // Change product code in row 2 to our product NOT in price list
        $sheet->setCellValue('L2', 'ACM00000059'); // Código de Producto
        $sheet->setCellValue('M2', 'ACM - SIN ENTRADA'); // Nombre Producto

        $modifiedFile = base_path('tests/Fixtures/test_product_not_in_price_list.xlsx');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($df, 'Xlsx');
        $writer->save($modifiedFile);

        // Act: Import the Excel file
        Excel::import(
            new OrderLinesImport($importProcess->id),
            $modifiedFile
        );

        // Assert: Verify import was successful
        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors'
        );

        // Verify order was created
        $this->assertEquals(1, Order::count(), 'Should have created 1 order');

        $order = Order::first();
        $this->assertNotNull($order, 'Order should exist');
        $this->assertEquals(OrderStatus::PROCESSED->value, $order->status);

        // Verify order lines were created (4 products total)
        $this->assertEquals(4, OrderLine::count(), 'Should have created 4 order lines');

        // Find the order line with product NOT in price list
        $product = Product::where('code', 'ACM00000059')->first();
        $this->assertNotNull($product, 'Product ACM00000059 should exist');

        $orderLine = OrderLine::where('order_id', $order->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($orderLine, 'Order line for ACM00000059 should exist');

        // TDD: CRITICAL ASSERTIONS - Will FAIL initially
        // When product NOT in price list, unit_price should be 0 (not NULL)
        $this->assertNotNull(
            $orderLine->unit_price,
            'unit_price should NOT be NULL - should be 0 when product not in price list'
        );

        $this->assertEquals(
            0,
            $orderLine->unit_price,
            'unit_price should be 0 when product not in price list'
        );

        // unit_price_with_tax should also be 0 (not NULL)
        $this->assertNotNull(
            $orderLine->unit_price_with_tax,
            'unit_price_with_tax should NOT be NULL - should be 0'
        );

        $this->assertEquals(
            0,
            $orderLine->unit_price_with_tax,
            'unit_price_with_tax should be 0 when product not in price list'
        );

        // Clean up
        if (file_exists($modifiedFile)) {
            unlink($modifiedFile);
        }
    }
}
