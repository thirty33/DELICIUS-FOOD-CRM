<?php

namespace Tests\Feature\Imports;

use App\Imports\OrderLinesImport;
use App\Models\ImportProcess;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Product;
use App\Enums\OrderStatus;
use Database\Seeders\OrderLinesImportTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Pilot Test for OrderLinesImport - Single Order
 *
 * Tests the import of a single order (20251103510024) with 4 products
 * to validate that the complete import flow works before testing with full dataset.
 *
 * Test Data:
 * - Order: 20251103510024
 * - User: RECEPCION@ALIACE.CL
 * - Company: ALIMENTOS Y ACEITES SPA (76.505.808-2)
 * - Products: 4 (ACM00000043, EXT00000001, PCH00000003, PTR00000005)
 * - Categories: 4
 */
class OrderLinesImportPilotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed test data
        $this->seed(OrderLinesImportTestSeeder::class);
    }

    public function test_can_import_single_order_successfully(): void
    {
        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');
        // Arrange: Verify initial state
        $this->assertEquals(0, Order::count(), 'Should start with 0 orders');
        $this->assertEquals(0, OrderLine::count(), 'Should start with 0 order lines');
        $this->assertEquals(1, User::count(), 'Should have 1 user seeded');
        $this->assertEquals(4, Product::count(), 'Should have 4 products seeded');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Get test Excel file
        $testFile = base_path('tests/Fixtures/test_single_order.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        // Act: Import the Excel file
        Excel::import(
            new OrderLinesImport($importProcess->id),
            $testFile
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
        $this->assertEquals('20251103510024', $order->order_number, 'Order number should match');
        $this->assertEquals(OrderStatus::PROCESSED->value, $order->status, 'Order status should be PROCESSED');

        // Verify user relationship
        $user = User::where('email', 'RECEPCION@ALIACE.CL')->first();
        $this->assertEquals($user->id, $order->user_id, 'Order should belong to correct user');

        // Verify dates
        // Note: created_at may be set to current date for new orders depending on import logic
        $this->assertNotNull($order->created_at, 'Order should have created_at');
        $this->assertStringStartsWith('2025-11-12', $order->dispatch_date, 'Dispatch date should match');

        // Verify order lines were created
        $this->assertEquals(4, OrderLine::count(), 'Should have created 4 order lines');
        $this->assertEquals(4, $order->orderLines->count(), 'Order should have 4 lines');

        // Verify each product line
        $expectedProducts = [
            'ACM00000043' => ['quantity' => 1, 'price' => 400],
            'EXT00000001' => ['quantity' => 1, 'price' => 100],
            'PCH00000003' => ['quantity' => 1, 'price' => 4600],
            'PTR00000005' => ['quantity' => 1, 'price' => 850],
        ];

        foreach ($expectedProducts as $productCode => $expected) {
            $product = Product::where('code', $productCode)->first();
            $this->assertNotNull($product, "Product {$productCode} should exist");

            $orderLine = OrderLine::where('order_id', $order->id)
                ->where('product_id', $product->id)
                ->first();

            $this->assertNotNull($orderLine, "Order line for {$productCode} should exist");
            $this->assertEquals(
                $expected['quantity'],
                $orderLine->quantity,
                "Quantity for {$productCode} should match"
            );
            $this->assertEquals(
                $expected['price'],
                $orderLine->unit_price,
                "Unit price for {$productCode} should match"
            );
        }

        // Verify order totals (calculated by model)
        $this->assertGreaterThan(0, $order->total, 'Order total should be calculated');
        $this->assertGreaterThan(0, $order->grand_total, 'Order grand_total should be calculated');
    }

    public function test_order_import_creates_all_required_relationships(): void
    {
        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $testFile = base_path('tests/Fixtures/test_single_order.xlsx');

        // Import
        Excel::import(
            new OrderLinesImport($importProcess->id),
            $testFile
        );

        $order = Order::first();

        // Test relationships are properly loaded
        $this->assertNotNull($order->user, 'Order should have user relationship');
        $this->assertNotNull($order->user->company, 'User should have company relationship');
        $this->assertNotNull($order->user->branch, 'User should have branch relationship');

        // Verify company matches Excel data
        $this->assertEquals(
            'ALIMENTOS Y ACEITES SPA',
            $order->user->company->name,
            'Company name should match'
        );
        $this->assertEquals(
            '76.505.808-2',
            $order->user->company->tax_id,
            'Company tax_id should match'
        );

        // Verify each order line has product with category
        foreach ($order->orderLines as $line) {
            $this->assertNotNull($line->product, 'Order line should have product');
            $this->assertNotNull($line->product->category, 'Product should have category');
            $this->assertNotEmpty($line->product->code, 'Product should have code');
            $this->assertNotEmpty($line->product->name, 'Product should have name');
        }
    }

    public function test_import_process_status_updates_correctly(): void
    {
        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $this->assertEquals(
            ImportProcess::STATUS_QUEUED,
            $importProcess->status,
            'Initial status should be QUEUED'
        );

        $testFile = base_path('tests/Fixtures/test_single_order.xlsx');

        // Import
        Excel::import(
            new OrderLinesImport($importProcess->id),
            $testFile
        );

        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Final status should be PROCESSED'
        );

        $this->assertNull(
            $importProcess->error_log,
            'Error log should be null for successful import'
        );
    }

    /**
     * Test Multi-Chunk Order Import - Validates Merge Functionality
     *
     * This test validates that when a single order's lines are split across
     * multiple chunks (chunk size = 100), the importer correctly merges
     * all lines into a single order instead of creating duplicates.
     *
     * Test Scenario:
     * - Order: 20251103999999
     * - Total Lines: 150
     * - Chunk 1: Lines 1-100 (creates order)
     * - Chunk 2: Lines 101-150 (should merge, not create new order)
     *
     * Expected Results:
     * - Only 1 Order created
     * - Order has 150 OrderLines
     * - All products correctly associated
     */
    public function test_order_split_across_chunks_merges_correctly(): void
    {
        // Note: setUp() already ran OrderLinesImportTestSeeder
        // Now seed additional multi-chunk test data
        $this->seed(\Database\Seeders\OrderLinesMultiChunkTestSeeder::class);

        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Arrange: Verify initial state BEFORE import
        // setUp() creates: 1 order with 4 lines (but we haven't imported yet, so 0 orders)
        $this->assertEquals(0, Order::count(), 'Should start with 0 orders (before import)');
        $this->assertEquals(0, OrderLine::count(), 'Should start with 0 order lines (before import)');
        // setUp() seeded 1 user, multi-chunk seeded 1 user = 2 total
        $this->assertEquals(2, User::count(), 'Should have 2 users seeded (1 from setUp + 1 from multi-chunk)');
        // setUp() seeded 4 products, multi-chunk seeded 150 products = 154 total
        $this->assertEquals(154, Product::count(), 'Should have 154 products seeded (4 from setUp + 150 from multi-chunk)');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Get test Excel file with 150 lines
        $testFile = base_path('tests/Fixtures/test_multi_chunk_order.xlsx');
        $this->assertFileExists($testFile, 'Multi-chunk test Excel file should exist');

        // Act: Import the Excel file
        // This will process in 2 chunks: lines 1-100, then lines 101-150
        Excel::import(
            new OrderLinesImport($importProcess->id),
            $testFile
        );

        // Assert: Verify import was successful
        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors'
        );

        // Critical Test: Verify only ONE order was created (not 2)
        // This test only imports the multi-chunk file, so only 1 order should exist
        $this->assertEquals(1, Order::count(), 'Should have created exactly 1 order from multi-chunk import');

        // Get the multi-chunk order
        $order = Order::where('order_number', '20251103999999')->first();
        $this->assertNotNull($order, 'Multi-chunk order should exist');

        // Verify order has ALL 150 lines (merged from both chunks)
        $this->assertEquals(150, $order->orderLines->count(), 'Order should have 150 lines from both chunks');

        // Verify total order lines in database
        // Only 150 from this multi-chunk import (no other imports ran in this test)
        $this->assertEquals(150, OrderLine::count(), 'Should have 150 total order lines from multi-chunk import');

        // Verify user relationship
        $user = User::where('email', 'MULTICHUNK@TEST.CL')->first();
        $this->assertEquals($user->id, $order->user_id, 'Order should belong to multi-chunk test user');

        // Verify order status
        $this->assertEquals(OrderStatus::PROCESSED->value, $order->status, 'Order status should be PROCESSED');

        // Verify sample products from different chunks
        // Product from Chunk 1 (line 1)
        $product1 = Product::where('code', 'TEST00000001')->first();
        $this->assertNotNull($product1, 'First product should exist');

        $orderLine1 = OrderLine::where('order_id', $order->id)
            ->where('product_id', $product1->id)
            ->first();
        $this->assertNotNull($orderLine1, 'Order line for first product should exist');
        $this->assertEquals(1, $orderLine1->quantity, 'Quantity for first product should be 1');
        $this->assertEquals(1001, $orderLine1->unit_price, 'Unit price for first product should be 1001');

        // Product from Chunk 1 (line 100 - last in first chunk)
        $product100 = Product::where('code', 'TEST00000100')->first();
        $this->assertNotNull($product100, 'Product 100 should exist');

        $orderLine100 = OrderLine::where('order_id', $order->id)
            ->where('product_id', $product100->id)
            ->first();
        $this->assertNotNull($orderLine100, 'Order line for product 100 should exist');

        // Product from Chunk 2 (line 101 - first in second chunk)
        $product101 = Product::where('code', 'TEST00000101')->first();
        $this->assertNotNull($product101, 'Product 101 should exist');

        $orderLine101 = OrderLine::where('order_id', $order->id)
            ->where('product_id', $product101->id)
            ->first();
        $this->assertNotNull($orderLine101, 'Order line for product 101 should exist (from second chunk)');

        // Product from Chunk 2 (line 150 - last in second chunk)
        $product150 = Product::where('code', 'TEST00000150')->first();
        $this->assertNotNull($product150, 'Last product should exist');

        $orderLine150 = OrderLine::where('order_id', $order->id)
            ->where('product_id', $product150->id)
            ->first();
        $this->assertNotNull($orderLine150, 'Order line for last product should exist');
        $this->assertEquals(1, $orderLine150->quantity, 'Quantity for last product should be 1');
        $this->assertEquals(1150, $orderLine150->unit_price, 'Unit price for last product should be 1150');

        // Verify order totals
        $this->assertGreaterThan(0, $order->total, 'Order total should be calculated');
        $this->assertGreaterThan(0, $order->grand_total, 'Order grand_total should be calculated');
    }

    /**
     * Test Minimum Order Amount Validation - Error Handling
     *
     * This test validates that when an order is imported that violates
     * the minimum order amount rule, the error is correctly logged in
     * the ImportProcess error_log with the row number and error message.
     *
     * Test Scenario:
     * - User with validate_min_price = true
     * - Branch with min_price_order = 50000 ($500)
     * - Order with only 1 product costing $4 (400 cents) - below minimum
     * - Expected: Import fails with MaxOrderAmountValidation error
     *
     * Expected Results:
     * - ImportProcess status = STATUS_PROCESSED_WITH_ERRORS
     * - error_log contains the validation error message
     * - error_log contains reference to the order that failed
     */
    public function test_minimum_order_amount_validation_error_is_logged(): void
    {
        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Arrange: Get test data from seeder
        $company = \App\Models\Company::where('tax_id', '76.505.808-2')->first();
        $branch = \App\Models\Branch::where('company_id', $company->id)->first();
        $priceList = \App\Models\PriceList::where('name', 'Lista General Test')->first();
        $category = \App\Models\Category::where('name', 'MINI ENSALADAS DE ACOMPAÑAMIENTO')->first();
        $role = \App\Models\Role::where('name', \App\Enums\RoleName::ADMIN->value)->first();

        // 1. Create user with validate_min_price = true
        $user = User::create([
            'name' => 'Test User Min Price',
            'nickname' => 'TEST.MINPRICE',
            'email' => 'test.minprice@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'validate_min_price' => true,  // Enable minimum price validation
        ]);

        $user->roles()->attach($role->id);

        // 2. Set branch minimum price order to $500 (50000 cents)
        $branch->update([
            'min_price_order' => 50000,  // $500 minimum
        ]);

        // 3. Create a single low-priced product ($4 = 400 cents)
        $product = Product::create([
            'code' => 'TEST-LOW-PRICE',
            'name' => 'Producto Bajo Precio',
            'description' => 'Producto de prueba con precio bajo',
            'category_id' => $category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'active' => true,
            'allow_sales_without_stock' => true,
        ]);

        // Add product to price list with low price
        \App\Models\PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 400,  // $4 (400 cents) - well below $500 minimum
        ]);

        // 4. Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // 5. Create Excel file with single low-value order
        $rows = [
            [
                'ID Orden' => null,
                'Código de Pedido' => '20251112MIN001',
                'Estado' => 'Procesado',
                'Fecha de Orden' => '12/11/2025',
                'Fecha de Despacho' => '13/11/2025',
                'Código de Empresa' => $company->tax_id,
                'Empresa' => $company->name,
                'Código Sucursal' => $branch->company->tax_id,
                'Nombre Fantasía Sucursal' => 'Test Branch',
                'Usuario' => 'TEST.MINPRICE',
                'Categoría' => $category->name,
                'Código de Producto' => 'TEST-LOW-PRICE',
                'Nombre Producto' => 'Producto Bajo Precio',
                'Cantidad' => 1,
                'Precio Neto' => 4,
                'Precio con Impuesto' => 4.76,
                'Precio Total Neto' => 4,
                'Precio Total con Impuesto' => 4.76,
                'Parcialmente Programado' => 0,
            ],
        ];

        $df = \PhpOffice\PhpSpreadsheet\IOFactory::load(base_path('tests/Fixtures/test_single_order.xlsx'));
        $sheet = $df->getActiveSheet();

        // Clear existing data (keep headers)
        $sheet->removeRow(2, $sheet->getHighestRow() - 1);

        // Write new row
        $rowIndex = 2;
        foreach ($rows as $row) {
            $colIndex = 1;
            foreach ($row as $value) {
                $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value);
                $colIndex++;
            }
            $rowIndex++;
        }

        $testFile = base_path('tests/Fixtures/test_min_order_violation.xlsx');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($df, 'Xlsx');
        $writer->save($testFile);

        // Act: Import the Excel file
        Excel::import(
            new OrderLinesImport($importProcess->id),
            $testFile
        );

        // Assert: Verify import completed with errors
        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
            $importProcess->status,
            'Import should complete with errors status'
        );

        $this->assertNotNull($importProcess->error_log, 'Error log should not be null');
        $this->assertIsArray($importProcess->error_log, 'Error log should be an array');
        $this->assertNotEmpty($importProcess->error_log, 'Error log should contain errors');

        // TDD: Verify error object structure
        // Expected error format:
        // [
        //     'row' => 2,  // Last row of the order in Excel (row 1 is header, row 2 is the only order line)
        //     'attribute' => 'order_20251112MIN001',
        //     'errors' => ['El monto del pedido mínimo es $500'],
        //     'values' => [...]
        // ]
        $errorFound = false;
        $rowNumberCorrect = false;

        foreach ($importProcess->error_log as $error) {
            // TDD: Assert error object has required fields
            $this->assertArrayHasKey('row', $error, 'Error object must have "row" field');
            $this->assertArrayHasKey('attribute', $error, 'Error object must have "attribute" field');
            $this->assertArrayHasKey('errors', $error, 'Error object must have "errors" field');

            $errorMessage = '';

            // Error can be in different formats
            if (isset($error['error'])) {
                $errorMessage = $error['error'];
            } elseif (isset($error['errors']) && is_array($error['errors'])) {
                $errorMessage = implode(' ', $error['errors']);
            }

            // Check if this is the minimum price validation error
            if (str_contains($errorMessage, 'El monto del pedido mínimo es')) {
                $errorFound = true;

                // TDD: Verify row number is 2 (last row of this order in Excel)
                // Excel structure:
                // Row 1: Headers
                // Row 2: Order 20251112MIN001, Product TEST-LOW-PRICE (this is the last/only line of the order)
                $this->assertEquals(
                    2,
                    $error['row'],
                    'Error row should be 2 (last Excel row of the order - row 1 is header, row 2 is the only order line)'
                );
                $rowNumberCorrect = true;
            }
        }

        $this->assertTrue($errorFound, 'Error log should contain minimum price validation error message');
        $this->assertTrue($rowNumberCorrect, 'Error should have correct row number (2 - last row of the order)');

        // TDD: Verify that order was NOT created due to transaction rollback
        // When validation fails in executeValidationChain(), the exception is caught
        // in processOrderRows() catch block, which calls DB::rollBack()
        // So the order should NOT exist in the database
        $order = Order::where('order_number', '20251112MIN001')->first();

        $this->assertNull(
            $order,
            'Order should NOT exist - transaction should rollback when validation fails'
        );

        // TDD: Verify no order lines were created either
        $orderLinesCount = OrderLine::count();
        $this->assertEquals(
            0,
            $orderLinesCount,
            'No order lines should exist when order creation fails due to validation'
        );

        // Clean up test file
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }
}
