<?php

namespace Tests\Feature\Imports;

use App\Imports\PriceListImport;
use App\Models\ImportProcess;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use Database\Seeders\PriceListImportTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Pilot Test for PriceListImport - Single Price List
 *
 * Tests the import of a single price list (LISTA TEST) with 4 products
 * to validate that the complete import flow works.
 *
 * Test Data:
 * - Price List: LISTA TEST
 * - Products: 4 (TEST-PRICE-001 to TEST-PRICE-004)
 * - Prices: $1,500.00, $2,750.00, $3,250.00, $4,890.00
 */
class PriceListImportPilotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed test data (products only, price list will be created by import)
        $this->seed(PriceListImportTestSeeder::class);
    }

    public function test_can_import_price_list_successfully(): void
    {
        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Arrange: Verify initial state
        $this->assertEquals(0, PriceList::count(), 'Should start with 0 price lists');
        $this->assertEquals(0, PriceListLine::count(), 'Should start with 0 price list lines');
        $this->assertEquals(4, Product::count(), 'Should have 4 products seeded');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRICE_LISTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Get test Excel file
        $testFile = base_path('tests/Fixtures/test_price_list.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        // Act: Import the Excel file (jobs will execute synchronously in test environment)
        Excel::import(
            new PriceListImport($importProcess->id),
            $testFile
        );

        // Give time for queued jobs to process
        // In testing environment with 'sync' queue, jobs execute immediately
        sleep(1);

        // Assert: Verify import was successful
        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors'
        );

        // Verify price list was created
        $this->assertEquals(1, PriceList::count(), 'Should have created 1 price list');

        $priceList = PriceList::first();
        $this->assertNotNull($priceList, 'Price list should exist');
        $this->assertEquals('LISTA TEST', $priceList->name, 'Price list name should match');

        // Verify price list lines were created (jobs processed)
        $this->assertEquals(4, PriceListLine::count(), 'Should have created 4 price list lines');
        $this->assertEquals(4, $priceList->priceListLines->count(), 'Price list should have 4 lines');

        // Verify each product has correct price
        // Note: unit_price is stored as float in database
        $expectedPrices = [
            'TEST-PRICE-001' => 150000.00, // $1,500.00 in cents (as float)
            'TEST-PRICE-002' => 275000.00, // $2,750.00 in cents (as float)
            'TEST-PRICE-003' => 325000.00, // $3,250.00 in cents (as float)
            'TEST-PRICE-004' => 489000.00, // $4,890.00 in cents (as float)
        ];

        foreach ($expectedPrices as $productCode => $expectedPrice) {
            $product = Product::where('code', $productCode)->first();
            $this->assertNotNull($product, "Product {$productCode} should exist");

            $priceListLine = PriceListLine::where('price_list_id', $priceList->id)
                ->where('product_id', $product->id)
                ->first();

            $this->assertNotNull($priceListLine, "Price list line for {$productCode} should exist");
            $this->assertEquals(
                $expectedPrice,
                $priceListLine->unit_price,
                "Unit price for {$productCode} should be {$expectedPrice} cents"
            );
        }
    }

    public function test_import_process_status_updates_correctly(): void
    {
        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRICE_LISTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $this->assertEquals(
            ImportProcess::STATUS_QUEUED,
            $importProcess->status,
            'Initial status should be QUEUED'
        );

        $testFile = base_path('tests/Fixtures/test_price_list.xlsx');

        // Import
        Excel::import(
            new PriceListImport($importProcess->id),
            $testFile
        );

        // Give time for jobs to process
        sleep(1);

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

    public function test_price_transformation_works_correctly(): void
    {
        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRICE_LISTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $testFile = base_path('tests/Fixtures/test_price_list.xlsx');

        // Import
        Excel::import(
            new PriceListImport($importProcess->id),
            $testFile
        );

        sleep(1);

        // Verify price list exists
        $priceList = PriceList::where('name', 'LISTA TEST')->first();
        $this->assertNotNull($priceList, 'Price list should be created');

        // Verify all 4 products have price list lines
        $priceListLineCount = PriceListLine::where('price_list_id', $priceList->id)->count();
        $this->assertEquals(4, $priceListLineCount, 'All 4 products should have price list lines created by jobs');

        // Verify price transformation from Excel format to database format
        // Excel: "$1,500.00" -> Database: 150000.00 (cents as float)
        // Excel: "$2,750.00" -> Database: 275000.00 (cents as float)
        // Excel: "$3,250.00" -> Database: 325000.00 (cents as float)
        // Excel: "$4,890.00" -> Database: 489000.00 (cents as float)

        $product1 = Product::where('code', 'TEST-PRICE-001')->first();
        $line1 = PriceListLine::where('price_list_id', $priceList->id)
            ->where('product_id', $product1->id)
            ->first();

        $this->assertNotNull($line1, 'Price list line for TEST-PRICE-001 should exist');
        $this->assertEquals(150000.00, $line1->unit_price, 'Price should be transformed from $1,500.00 to 150000 cents');

        // Verify that the transformation actually happened (not just copied as string)
        // Note: unit_price column is defined as float in database migration
        $this->assertIsNumeric($line1->unit_price, 'Price should be stored as numeric value');
        $this->assertGreaterThan(0, $line1->unit_price, 'Price should be positive');

        // Verify the price transformation is correct (within float precision)
        $this->assertEqualsWithDelta(150000.00, floatval($line1->unit_price), 0.01, 'Price should be 150000 cents (within float precision)');
    }

    /**
     * Test Multi-Chunk Price List Import - Validates Chunk Processing
     *
     * This test validates that when a single price list has more lines than the chunk size (100),
     * all price list lines are correctly created across multiple chunks.
     *
     * Test Scenario:
     * - Price List: LISTA TEST MULTI-CHUNK
     * - Total Lines: 150
     * - Chunk 1: Lines 1-100 (creates price list)
     * - Chunk 2: Lines 101-150 (adds more lines to same price list)
     *
     * Expected Results:
     * - Only 1 PriceList created
     * - PriceList has 150 PriceListLines
     * - All products correctly associated
     */
    public function test_price_list_split_across_chunks_imports_correctly(): void
    {
        // Seed multi-chunk test data (150 products)
        $this->seed(\Database\Seeders\PriceListMultiChunkTestSeeder::class);

        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Arrange: Verify initial state BEFORE import
        // setUp() seeded 4 products, multi-chunk seeded 150 products = 154 total
        $this->assertEquals(0, PriceList::count(), 'Should start with 0 price lists (before import)');
        $this->assertEquals(0, PriceListLine::count(), 'Should start with 0 price list lines (before import)');
        $this->assertEquals(154, Product::count(), 'Should have 154 products seeded (4 from setUp + 150 from multi-chunk)');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRICE_LISTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Get test Excel file with 150 lines
        $testFile = base_path('tests/Fixtures/test_multi_chunk_price_list.xlsx');
        $this->assertFileExists($testFile, 'Multi-chunk test Excel file should exist');

        // Act: Import the Excel file
        // This will process in 2 chunks: lines 1-100, then lines 101-150
        Excel::import(
            new PriceListImport($importProcess->id),
            $testFile
        );

        // Give time for queued jobs to process
        sleep(1);

        // Assert: Verify import was successful
        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors'
        );

        // Critical Test: Verify only ONE price list was created (not 2)
        $this->assertEquals(1, PriceList::count(), 'Should have created exactly 1 price list from multi-chunk import');

        // Get the multi-chunk price list
        $priceList = PriceList::where('name', 'LISTA TEST MULTI-CHUNK')->first();
        $this->assertNotNull($priceList, 'Multi-chunk price list should exist');
        $this->assertEquals('LISTA TEST MULTI-CHUNK', $priceList->name, 'Price list name should match');

        // Verify price list has ALL 150 lines (processed from both chunks)
        // Note: Jobs execute synchronously in test environment
        $this->assertEquals(150, PriceListLine::count(), 'Should have created 150 price list lines from both chunks');
        $this->assertEquals(150, $priceList->priceListLines->count(), 'Price list should have 150 lines from both chunks');

        // Verify sample products from different chunks
        // Product from Chunk 1 (line 1)
        $product1 = Product::where('code', 'TEST-MULTI-PRICE-001')->first();
        $this->assertNotNull($product1, 'First product should exist');

        $priceListLine1 = PriceListLine::where('price_list_id', $priceList->id)
            ->where('product_id', $product1->id)
            ->first();
        $this->assertNotNull($priceListLine1, 'Price list line for first product should exist');
        $this->assertEquals(100100.00, $priceListLine1->unit_price, 'Unit price for first product should be 100100 cents ($1,001.00)');

        // Product from Chunk 1 (line 100 - last in first chunk)
        $product100 = Product::where('code', 'TEST-MULTI-PRICE-100')->first();
        $this->assertNotNull($product100, 'Product 100 should exist');

        $priceListLine100 = PriceListLine::where('price_list_id', $priceList->id)
            ->where('product_id', $product100->id)
            ->first();
        $this->assertNotNull($priceListLine100, 'Price list line for product 100 should exist');
        $this->assertEquals(110000.00, $priceListLine100->unit_price, 'Unit price for product 100 should be 110000 cents ($1,100.00)');

        // Product from Chunk 2 (line 101 - first in second chunk)
        $product101 = Product::where('code', 'TEST-MULTI-PRICE-101')->first();
        $this->assertNotNull($product101, 'Product 101 should exist');

        $priceListLine101 = PriceListLine::where('price_list_id', $priceList->id)
            ->where('product_id', $product101->id)
            ->first();
        $this->assertNotNull($priceListLine101, 'Price list line for product 101 should exist (from second chunk)');
        $this->assertEquals(110100.00, $priceListLine101->unit_price, 'Unit price for product 101 should be 110100 cents ($1,101.00)');

        // Product from Chunk 2 (line 150 - last in second chunk)
        $product150 = Product::where('code', 'TEST-MULTI-PRICE-150')->first();
        $this->assertNotNull($product150, 'Last product should exist');

        $priceListLine150 = PriceListLine::where('price_list_id', $priceList->id)
            ->where('product_id', $product150->id)
            ->first();
        $this->assertNotNull($priceListLine150, 'Price list line for last product should exist');
        $this->assertEquals(115000.00, $priceListLine150->unit_price, 'Unit price for last product should be 115000 cents ($1,150.00)');
    }
}
