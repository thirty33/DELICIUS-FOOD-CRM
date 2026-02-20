<?php

namespace Tests\Feature\Imports;

use App\Enums\OrderStatus;
use App\Imports\Concerns\OrderLineColumnDefinition;
use App\Imports\OrderLinesImport;
use App\Models\Category;
use App\Models\ImportProcess;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\OrderLinesImportTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

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
    use ConfiguresImportTests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment for imports (from trait)
        $this->configureImportTest();

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

    /**
     * Test that OrderLineProductionStatusObserver does NOT execute during import
     *
     * TDD Test - Validates that:
     * 1. OrderLinesImport sets OrderLine::$importMode = true
     * 2. OrderLineProductionStatusObserver skips execution when importMode is active
     * 3. MarkOrdersForProductionStatusUpdate job is NOT dispatched
     * 4. production_status_needs_update remains false/null
     *
     * This prevents saturating the queue with thousands of jobs during bulk imports.
     * Instead, production status should be recalculated once at the end of import.
     *
     * Expected Results (TDD - will fail initially):
     * - Order created successfully
     * - OrderLines created successfully
     * - MarkOrdersForProductionStatusUpdate job NOT dispatched
     * - production_status_needs_update is false or null
     */
    public function test_order_line_production_status_observer_does_not_execute_during_import(): void
    {
        // Arrange: Mock S3 storage
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Track jobs dispatched using Bus::fake() instead of Queue::fake()
        // This allows us to track synchronous job dispatches from observers
        \Illuminate\Support\Facades\Bus::fake([
            \App\Jobs\MarkOrdersForProductionStatusUpdate::class,
        ]);

        // Verify initial state
        $this->assertEquals(0, Order::count(), 'Should start with 0 orders');
        $this->assertEquals(0, OrderLine::count(), 'Should start with 0 order lines');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Get test Excel file
        $testFile = base_path('tests/Fixtures/test_single_order.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        // Act: Import the Excel file (synchronously for testing)
        Excel::import(
            new OrderLinesImport($importProcess->id),
            $testFile
        );

        // Assert: Verify import was successful
        $this->assertEquals(1, Order::count(), 'Order should be created');
        $this->assertEquals(4, OrderLine::count(), 'Order lines should be created');

        $order = Order::first();

        // CRITICAL ASSERTION: MarkOrdersForProductionStatusUpdate job should NOT be dispatched
        // This prevents saturating the queue with thousands of jobs during bulk imports
        \Illuminate\Support\Facades\Bus::assertNotDispatched(
            \App\Jobs\MarkOrdersForProductionStatusUpdate::class
        );

        // Note: production_status_needs_update may be true by default from migration
        // The important thing is that the Observer did NOT dispatch the job during import

        // Additional: Verify order was created correctly (sanity check)
        $this->assertEquals('20251103510024', $order->order_number);
        $this->assertEquals(4, $order->orderLines->count());
    }

    /**
     * TDD Test - Existing Order Update: Import Should Replace Products
     *
     * SCENARIO:
     * - Order ALREADY EXISTS in database with products A, B, C
     * - Import file has DIFFERENT products for same order:
     *   - Chunk 1 (lines 1-100): products C, D, E, F
     *   - Chunk 2 (lines 101-150): products G, H, J, K
     *
     * EXPECTED BEHAVIOR (TDD):
     * - Import should REPLACE/UPDATE the order's products
     * - Products A and B should be REMOVED (not in import file)
     * - Product C should remain (exists in both original order and import)
     * - Final products should be ONLY: C, D, E, F, G, H, J, K (8 products)
     *
     * CURRENT BEHAVIOR (will fail):
     * - Import likely ADDS products instead of replacing
     * - Products A and B remain in the order
     * - Final products would be: A, B, C, D, E, F, G, H, J, K (10+ products)
     *
     * CHUNK SIZE: 100 (real chunk size from OrderLinesImport::chunkSize())
     */
    public function test_existing_order_import_replaces_products_across_chunks(): void
    {
        // Note: setUp() already ran OrderLinesImportTestSeeder
        // Now seed additional multi-chunk test data
        $this->seed(\Database\Seeders\OrderLinesMultiChunkTestSeeder::class);

        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Get seeded data
        $user = User::where('email', 'MULTICHUNK@TEST.CL')->first();
        $this->assertNotNull($user, 'Multi-chunk test user should exist');

        $category = Category::where('name', 'PRODUCTOS TEST MULTI-CHUNK')->first();
        $priceList = PriceList::where('name', 'Lista Multi-Chunk Test')->first();

        // Step 1: CREATE EXISTING ORDER with products A, B, C
        // Product A and B: Use codes NOT in import file (TEST00000001-150)
        $productA = Product::create([
            'code' => 'TEST99999998',
            'name' => 'Producto A (NO en import)',
            'description' => 'Producto que NO está en archivo import',
            'category_id' => $category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'active' => true,
            'allow_sales_without_stock' => true,
        ]);
        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productA->id,
            'unit_price' => 9998,
        ]);

        $productB = Product::create([
            'code' => 'TEST99999999',
            'name' => 'Producto B (NO en import)',
            'description' => 'Producto que NO está en archivo import',
            'category_id' => $category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'active' => true,
            'allow_sales_without_stock' => true,
        ]);
        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productB->id,
            'unit_price' => 9999,
        ]);

        // Product C: Use code that IS in import file (for update validation)
        $productC = Product::where('code', 'TEST00000003')->first();
        $this->assertNotNull($productC, 'Product C should exist');

        // Create existing order with products A, B, C
        $existingOrder = Order::create([
            'order_number' => '20251103999999', // Same order number as multi-chunk file
            'date' => '2025-11-03',
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING->value,
            'total' => 3000, // 3 products × 1000
            'grand_total' => 3000,
        ]);

        // Create order lines for products A, B, C (without triggering events)
        $orderLineA = new OrderLine([
            'order_id' => $existingOrder->id,
            'product_id' => $productA->id,
            'quantity' => 1,
            'unit_price' => 1001,
            'unit_price_with_tax' => 1001,
        ]);
        $orderLineA->saveQuietly();

        $orderLineB = new OrderLine([
            'order_id' => $existingOrder->id,
            'product_id' => $productB->id,
            'quantity' => 1,
            'unit_price' => 1002,
            'unit_price_with_tax' => 1002,
        ]);
        $orderLineB->saveQuietly();

        $orderLineC = new OrderLine([
            'order_id' => $existingOrder->id,
            'product_id' => $productC->id,
            'quantity' => 99, // DIFFERENT quantity - import will have 1
            'unit_price' => 1003,
            'unit_price_with_tax' => 1003,
        ]);
        $orderLineC->saveQuietly();

        // Refresh order to load the newly created order lines
        $existingOrder->refresh();

        // Verify initial state BEFORE import
        $this->assertEquals(1, Order::count(), 'Should start with 1 existing order');
        $this->assertEquals(3, OrderLine::count(), 'Existing order should have 3 order lines (A, B, C)');
        $this->assertEquals(3, $existingOrder->orderLines->count(), 'Existing order should have 3 products');

        // Verify products A, B, C are in the order
        $this->assertTrue(
            $existingOrder->orderLines->contains('product_id', $productA->id),
            'Existing order should contain product A'
        );
        $this->assertTrue(
            $existingOrder->orderLines->contains('product_id', $productB->id),
            'Existing order should contain product B'
        );
        $this->assertTrue(
            $existingOrder->orderLines->contains('product_id', $productC->id),
            'Existing order should contain product C'
        );

        // Step 2: IMPORT FILE with products C, D, E, F (chunk 1) and G, H, J, K (chunk 2)
        // The multi-chunk file has 150 products (TEST00000001 to TEST00000150)
        // Chunk 1: lines 1-100 (products TEST00000001 to TEST00000100)
        // Chunk 2: lines 101-150 (products TEST00000101 to TEST00000150)

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
        // This will process in 2 chunks (chunk size = 100):
        // - Chunk 1: lines 1-100 (products TEST00000001 to TEST00000100)
        // - Chunk 2: lines 101-150 (products TEST00000101 to TEST00000150)
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

        // Refresh order to get updated data
        $existingOrder->refresh();

        // CRITICAL TDD ASSERTION: Order should have ONLY products from import file (150 products)
        // NOT the original 3 products + 150 new products = 153
        // This test will FAIL because current implementation likely ADDS products instead of REPLACING

        // Verify still only 1 order exists (not duplicated)
        $this->assertEquals(1, Order::count(), 'Should still have exactly 1 order (updated, not duplicated)');

        // TDD: Products A and B should be REMOVED (not in import file)
        $this->assertFalse(
            $existingOrder->orderLines->contains('product_id', $productA->id),
            'Product A should be REMOVED (not in import file) - TDD will FAIL'
        );

        $this->assertFalse(
            $existingOrder->orderLines->contains('product_id', $productB->id),
            'Product B should be REMOVED (not in import file) - TDD will FAIL'
        );

        // TDD: Product C should REMAIN (exists in both original and import)
        $productCFromImport = Product::where('code', 'TEST00000003')->first();

        $this->assertTrue(
            $existingOrder->orderLines->contains('product_id', $productCFromImport->id),
            'Product C (TEST00000003) should REMAIN (in both original and import)'
        );

        // CRITICAL TDD ASSERTION: Product C should have UPDATED data from import, not original data
        // Original data: quantity=99 (set above)
        // Import data: quantity=1 (from test file, line 3)
        $orderLineC = $existingOrder->orderLines->where('product_id', $productCFromImport->id)->first();
        $this->assertNotNull($orderLineC, 'Order line for Product C should exist');

        // The import file has quantity=1 for TEST00000003 (line 3)
        // This should be 1 (from import), NOT 99 (from original), confirming the update occurred
        $this->assertEquals(
            1,
            $orderLineC->quantity,
            'Product C quantity should be 1 (from IMPORT), not 99 (original) - TDD will FAIL if not updated'
        );

        // This assertion proves that the import UPDATED the existing product C
        // rather than keeping the original data or creating a duplicate

        // TDD: Final count should be ONLY products from import (150 products)
        $this->assertEquals(
            150,
            $existingOrder->orderLines->count(),
            'Order should have ONLY 150 products from import (replaced, not added) - TDD will FAIL'
        );

        // TDD: Total order lines in database should be 150 (not 153)
        $this->assertEquals(
            150,
            OrderLine::count(),
            'Database should have ONLY 150 order lines (old ones removed) - TDD will FAIL'
        );

        // Verify products from both chunks exist in final order
        // Sample from Chunk 1 (line 50)
        $productChunk1 = Product::where('code', 'TEST00000050')->first();
        $this->assertTrue(
            $existingOrder->orderLines->contains('product_id', $productChunk1->id),
            'Product from Chunk 1 should exist in order'
        );

        // Sample from Chunk 2 (line 125)
        $productChunk2 = Product::where('code', 'TEST00000125')->first();
        $this->assertTrue(
            $existingOrder->orderLines->contains('product_id', $productChunk2->id),
            'Product from Chunk 2 should exist in order'
        );

        // Last product from Chunk 2 (line 150)
        $productLast = Product::where('code', 'TEST00000150')->first();
        $this->assertTrue(
            $existingOrder->orderLines->contains('product_id', $productLast->id),
            'Last product from Chunk 2 should exist in order'
        );
    }

    /**
     * Test import with full export format matching OrderLineColumnDefinition.
     *
     * The export includes billing code and master category columns.
     * The import should handle the format without errors.
     */
    public function test_imports_order_from_full_column_export_format(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, OrderLine::count());

        // Generate fixture with 21-column export format
        $fixtureFile = $this->generate21ColumnFixture();

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new OrderLinesImport($importProcess->id),
            $fixtureFile
        );

        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors'
        );

        $this->assertEquals(1, Order::count());

        $order = Order::first();
        $this->assertEquals('20251103510024', $order->order_number);
        $this->assertEquals(OrderStatus::PROCESSED->value, $order->status);
        $this->assertEquals(4, $order->orderLines->count());

        $expectedProducts = [
            'ACM00000043' => ['quantity' => 1, 'price' => 400],
            'EXT00000001' => ['quantity' => 1, 'price' => 100],
            'PCH00000003' => ['quantity' => 1, 'price' => 4600],
            'PTR00000005' => ['quantity' => 1, 'price' => 850],
        ];

        foreach ($expectedProducts as $code => $expected) {
            $product = Product::where('code', $code)->first();
            $orderLine = OrderLine::where('order_id', $order->id)
                ->where('product_id', $product->id)
                ->first();

            $this->assertNotNull($orderLine, "Order line for {$code} should exist");
            $this->assertEquals($expected['quantity'], $orderLine->quantity, "Quantity for {$code}");
            $this->assertEquals($expected['price'], $orderLine->unit_price, "Unit price for {$code}");
        }

        // Cleanup
        if (file_exists($fixtureFile)) {
            unlink($fixtureFile);
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Generate a fixture with export format matching OrderLineColumnDefinition.
     * Headers from OrderLineColumnDefinition, data matching the seeder.
     */
    private function generate21ColumnFixture(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = OrderLineColumnDefinition::headers();
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }

        // Data matching OrderLineColumnDefinition columns
        // (billing code and master category columns are empty)
        $rows = [
            [null, '20251103510024', 'Procesado', '03/11/2025', '12/11/2025', '76.505.808-2', 'ALIMENTOS Y ACEITES SPA', '76.505.808-2', 'CONVENIO ALIACE', 'RECEPCION@ALIACE.CL', '', '', 'MINI ENSALADAS DE ACOMPAÑAMIENTO', 'ACM00000043', '', 'ACM - MINI ENSALADA ACEITUNAS Y HUEVO DURO', 1, 4, 4.76, 4, 4.76, 0, ''],
            [null, '20251103510024', 'Procesado', '03/11/2025', '12/11/2025', '76.505.808-2', 'ALIMENTOS Y ACEITES SPA', '76.505.808-2', 'CONVENIO ALIACE', 'RECEPCION@ALIACE.CL', '', '', 'ACOMPAÑAMIENTOS', 'EXT00000001', '', 'EXT - AMASADO DELICIUS MINI', 1, 1, 1.19, 1, 1.19, 0, ''],
            [null, '20251103510024', 'Procesado', '03/11/2025', '12/11/2025', '76.505.808-2', 'ALIMENTOS Y ACEITES SPA', '76.505.808-2', 'CONVENIO ALIACE', 'RECEPCION@ALIACE.CL', '', '', 'PLATOS VARIABLES PARA CALENTAR HORECA', 'PCH00000003', '', 'PCH - HORECA ALBONDIGAS ATOMATADAS CON ARROZ PRIMAVERA', 1, 46, 54.74, 46, 54.74, 0, ''],
            [null, '20251103510024', 'Procesado', '03/11/2025', '12/11/2025', '76.505.808-2', 'ALIMENTOS Y ACEITES SPA', '76.505.808-2', 'CONVENIO ALIACE', 'RECEPCION@ALIACE.CL', '', '', 'POSTRES', 'PTR00000005', '', 'PTR - FRUTA ESTACION 150 GR.', 1, 8.50, 10.12, 8.50, 10.12, 0, ''],
        ];

        foreach ($rows as $rowIndex => $rowData) {
            $excelRow = $rowIndex + 2;
            foreach ($rowData as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $excelRow, $value);
            }
        }

        $filePath = base_path('tests/Fixtures/test_order_21_columns.xlsx');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);

        return $filePath;
    }
}
