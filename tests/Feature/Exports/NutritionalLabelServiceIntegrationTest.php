<?php

namespace Tests\Feature\Exports;

use App\Contracts\NutritionalLabelDataPreparerInterface;
use App\Jobs\GenerateNutritionalLabelsJob;
use App\Models\Category;
use App\Models\NutritionalInformation;
use App\Models\Product;
use App\Models\ProductionArea;
use App\Services\Labels\NutritionalLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * NutritionalLabelService Integration Test
 *
 * Tests the complete flow from Service → Job dispatch with correct product_start_indexes.
 *
 * PRODUCTION BUG SCENARIO (OP-147):
 * - Product 1813 (PASTA ALFREDO SUPREMA) has 20 labels total
 * - Chunk size is 100
 * - Chunk 1 ends with 8 labels of product 1813 (labels 1-8)
 * - Chunk 2 starts with 12 labels of product 1813
 *
 * BUG: Chunk 2 starts product 1813 at label 101 (uses global start_index)
 * EXPECTED: Chunk 2 should continue product 1813 at label 9
 *
 * This test validates that the Service passes correct product_start_indexes to Jobs.
 */
class NutritionalLabelServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private NutritionalLabelDataPreparerInterface $preparer;
    private NutritionalLabelService $service;
    private Category $category;
    private ProductionArea $productionArea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparer = app(NutritionalLabelDataPreparerInterface::class);
        $this->service = app(NutritionalLabelService::class);

        $this->category = Category::create([
            'name' => 'TEST CATEGORY',
            'description' => 'Test category for nutritional labels',
        ]);

        $this->productionArea = ProductionArea::create([
            'name' => 'CUARTO FRIO',
            'description' => 'Test production area',
        ]);
    }

    /**
     * Helper to create a product with nutritional information
     */
    private function createProductWithNutritionalInfo(string $name, string $code): Product
    {
        $product = Product::create([
            'name' => $name,
            'code' => $code,
            'description' => "Test product {$name}",
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 100,
            'allow_sales_without_stock' => true,
        ]);

        $product->productionAreas()->attach($this->productionArea->id);

        NutritionalInformation::create([
            'product_id' => $product->id,
            'portion_size' => 100,
            'portion_unit' => 'g',
            'servings_per_package' => 1,
            'generate_label' => true,
            'ingredients' => 'Test ingredients',
            'allergens' => 'Test allergens',
        ]);

        return $product;
    }

    /**
     * Test 1: Service should pass product_start_indexes to Jobs, not global start_index
     *
     * This test validates that when a product is split across multiple chunks,
     * each Job receives the correct product_start_indexes to continue the sequence.
     *
     * SCENARIO:
     * - Product A: 92 labels (fills most of chunk 1)
     * - Product B: 20 labels (8 in chunk 1, 12 in chunk 2)
     * - Product C: 5 labels (all in chunk 2)
     *
     * EXPECTED Job parameters:
     * - Job 1: productStartIndexes = [A => 1, B => 1]
     * - Job 2: productStartIndexes = [B => 9, C => 1]
     *
     * CURRENT BUG: Service passes int $startIndex instead of array $productStartIndexes
     * This test will FAIL until Service and Job are updated.
     */
    public function test_service_passes_product_start_indexes_to_jobs(): void
    {
        // Create products
        $productA = $this->createProductWithNutritionalInfo('PRODUCT A', 'PROD-A');
        $productB = $this->createProductWithNutritionalInfo('PRODUCT B (SPLIT)', 'PROD-B');
        $productC = $this->createProductWithNutritionalInfo('PRODUCT C', 'PROD-C');

        $quantities = [
            $productA->id => 92,
            $productB->id => 20,
            $productC->id => 5,
        ];

        // Capture Jobs that are dispatched
        $dispatchedJobs = [];
        Bus::fake();

        // Call the service
        $this->service->generateLabels(
            [$productA->id, $productB->id, $productC->id],
            '15/12/2025',
            $quantities
        );

        // Use reflection to inspect Job parameters
        // The 7th constructor parameter is startIndex (should be array, currently int)
        Bus::assertChained([
            function (GenerateNutritionalLabelsJob $job) use ($productA, $productB) {
                // Use reflection to get the private property
                $reflection = new \ReflectionClass($job);
                $property = $reflection->getProperty('startIndex');
                $property->setAccessible(true);
                $startIndex = $property->getValue($job);

                // CRITICAL: This should be an array with product_start_indexes
                // Currently it's an int (1), which is WRONG
                $this->assertIsArray(
                    $startIndex,
                    'Job 1 startIndex should be an array (product_start_indexes), not int. Service needs to pass $chunk[\'product_start_indexes\'] instead of $chunk[\'start_index\']'
                );

                // Verify correct values
                $this->assertEquals(1, $startIndex[$productA->id], 'Job 1: Product A should start at 1');
                $this->assertEquals(1, $startIndex[$productB->id], 'Job 1: Product B should start at 1');

                return true;
            },
            function (GenerateNutritionalLabelsJob $job) use ($productB, $productC) {
                $reflection = new \ReflectionClass($job);
                $property = $reflection->getProperty('startIndex');
                $property->setAccessible(true);
                $startIndex = $property->getValue($job);

                // CRITICAL: This should be an array
                $this->assertIsArray(
                    $startIndex,
                    'Job 2 startIndex should be an array (product_start_indexes), not int'
                );

                // Product B should continue at 9 (not 101!)
                $this->assertEquals(9, $startIndex[$productB->id], 'Job 2: Product B should CONTINUE at 9');
                $this->assertEquals(1, $startIndex[$productC->id], 'Job 2: Product C should start at 1');

                return true;
            },
        ]);
    }

    /**
     * Test 2: Simulate complete flow - Job execution with product_start_indexes
     *
     * This test simulates what happens when Jobs are executed with the
     * product_start_indexes data from prepareData.
     *
     * VALIDATES: The entire pipeline produces correct label_index values.
     */
    public function test_complete_flow_with_product_start_indexes(): void
    {
        // Create products
        $productA = $this->createProductWithNutritionalInfo('PRODUCT A', 'PROD-A');
        $productB = $this->createProductWithNutritionalInfo('PRODUCT B (SPLIT)', 'PROD-B');
        $productC = $this->createProductWithNutritionalInfo('PRODUCT C', 'PROD-C');

        $quantities = [
            $productA->id => 92,
            $productB->id => 20,
            $productC->id => 5,
        ];

        // Get prepared data (this is what Service generates)
        $preparedData = $this->preparer->prepareData(
            [$productA->id, $productB->id, $productC->id],
            $quantities,
            100
        );

        // Simulate what GenerateNutritionalLabelsJob does:
        // It calls getExpandedProducts with the chunk data
        $chunk1 = $preparedData['chunks'][0];
        $chunk2 = $preparedData['chunks'][1];

        // Simulate Job 1 execution (what the Job SHOULD do)
        $chunk1Products = $this->preparer->getExpandedProducts(
            $chunk1['product_ids'],
            $chunk1['quantities'],
            $chunk1['product_start_indexes'] // Using product_start_indexes instead of start_index
        );

        // Simulate Job 2 execution
        $chunk2Products = $this->preparer->getExpandedProducts(
            $chunk2['product_ids'],
            $chunk2['quantities'],
            $chunk2['product_start_indexes'] // Using product_start_indexes instead of start_index
        );

        // Verify Chunk 1 results
        $chunk1ProductA = $chunk1Products->filter(fn($p) => $p->id === $productA->id);
        $chunk1ProductB = $chunk1Products->filter(fn($p) => $p->id === $productB->id);

        $this->assertEquals(
            range(1, 92),
            $chunk1ProductA->pluck('label_index')->values()->toArray(),
            'Chunk 1: Product A should have labels 1-92'
        );

        $this->assertEquals(
            range(1, 8),
            $chunk1ProductB->pluck('label_index')->values()->toArray(),
            'Chunk 1: Product B should have labels 1-8'
        );

        // Verify Chunk 2 results - THIS IS THE CRITICAL TEST
        $chunk2ProductB = $chunk2Products->filter(fn($p) => $p->id === $productB->id);
        $chunk2ProductC = $chunk2Products->filter(fn($p) => $p->id === $productC->id);

        $this->assertEquals(
            range(9, 20),
            $chunk2ProductB->pluck('label_index')->values()->toArray(),
            'Chunk 2: Product B should CONTINUE with labels 9-20 (not 101-112)'
        );

        $this->assertEquals(
            range(1, 5),
            $chunk2ProductC->pluck('label_index')->values()->toArray(),
            'Chunk 2: Product C should have labels 1-5'
        );
    }

    /**
     * Test 3: Verify Service currently passes start_index (documents the bug)
     *
     * This test documents the CURRENT behavior where the Service passes
     * start_index (deprecated) instead of product_start_indexes.
     *
     * CURRENT BUG: Service line 83 passes $chunk['start_index'] to Job
     * SHOULD BE: Service should pass $chunk['product_start_indexes'] to Job
     *
     * This test will PASS because it documents current (buggy) behavior.
     * After fixing the Service, update this test to validate correct behavior.
     */
    public function test_service_currently_passes_deprecated_start_index(): void
    {
        // Prepare data and verify that both fields exist
        $productA = $this->createProductWithNutritionalInfo('PRODUCT A', 'PROD-A');

        $preparedData = $this->preparer->prepareData(
            [$productA->id],
            [$productA->id => 5],
            2
        );

        // Verify prepareData returns BOTH fields (for backwards compatibility)
        $chunk = $preparedData['chunks'][0];

        // start_index exists (deprecated, for backwards compatibility)
        $this->assertArrayHasKey('start_index', $chunk, 'Chunk should have deprecated start_index for backwards compatibility');

        // product_start_indexes exists (new, correct approach)
        $this->assertArrayHasKey('product_start_indexes', $chunk, 'Chunk should have product_start_indexes');

        // The deprecated start_index is always 1 now
        $this->assertEquals(1, $chunk['start_index'], 'Deprecated start_index should always be 1');

        // product_start_indexes contains per-product values
        $this->assertIsArray($chunk['product_start_indexes'], 'product_start_indexes should be an array');
        $this->assertEquals(1, $chunk['product_start_indexes'][$productA->id], 'Product A should start at 1');
    }
}