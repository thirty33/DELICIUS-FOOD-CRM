<?php

namespace Tests\Unit\Services;

use App\Contracts\NutritionalLabelDataPreparerInterface;
use App\Models\Category;
use App\Models\NutritionalInformation;
use App\Models\Product;
use App\Models\ProductionArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test NutritionalLabelDataPreparer
 *
 * Tests the label_index generation logic for nutritional labels.
 *
 * BUSINESS REQUIREMENT:
 * - When generating labels for a single product across multiple chunks, counter should continue
 * - When generating labels for multiple products, each product should have its own counter
 * - Counter should reset when product changes
 */
class NutritionalLabelDataPreparerTest extends TestCase
{
    use RefreshDatabase;

    private NutritionalLabelDataPreparerInterface $preparer;
    private Category $category;
    private ProductionArea $productionArea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparer = app(NutritionalLabelDataPreparerInterface::class);

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
     * Test 1: Same product in multiple chunks maintains sequence
     *
     * SCENARIO:
     * - Product A with quantity 5
     * - Chunk size of 2 (simulating split across chunks)
     *
     * EXPECTED (current behavior - should PASS):
     * - Chunk 1: labels 1, 2
     * - Chunk 2: labels 3, 4
     * - Chunk 3: labels 5
     *
     * This validates that when a single product is split across multiple chunks,
     * the counter continues sequentially (not restarting at each chunk).
     */
    public function test_same_product_in_multiple_chunks_maintains_sequence(): void
    {
        // Create single product
        $productA = $this->createProductWithNutritionalInfo('GOHAN POLLO TEMPURA', 'GOH-001');

        // Prepare data with chunk size of 2 to force splitting
        $quantities = [$productA->id => 5];
        $preparedData = $this->preparer->prepareData([$productA->id], $quantities, 2);

        // Should have 3 chunks (2+2+1)
        $this->assertCount(3, $preparedData['chunks'], 'Should have 3 chunks for 5 labels with chunk size 2');

        // Verify product_start_indexes for each chunk (same product continues sequence)
        $this->assertEquals([$productA->id => 1], $preparedData['chunks'][0]['product_start_indexes'], 'Chunk 1 should start product A at index 1');
        $this->assertEquals([$productA->id => 3], $preparedData['chunks'][1]['product_start_indexes'], 'Chunk 2 should start product A at index 3');
        $this->assertEquals([$productA->id => 5], $preparedData['chunks'][2]['product_start_indexes'], 'Chunk 3 should start product A at index 5');

        // Get expanded products for each chunk using product_start_indexes
        $chunk1Products = $this->preparer->getExpandedProducts(
            $preparedData['chunks'][0]['product_ids'],
            $preparedData['chunks'][0]['quantities'],
            $preparedData['chunks'][0]['product_start_indexes']
        );

        $chunk2Products = $this->preparer->getExpandedProducts(
            $preparedData['chunks'][1]['product_ids'],
            $preparedData['chunks'][1]['quantities'],
            $preparedData['chunks'][1]['product_start_indexes']
        );

        $chunk3Products = $this->preparer->getExpandedProducts(
            $preparedData['chunks'][2]['product_ids'],
            $preparedData['chunks'][2]['quantities'],
            $preparedData['chunks'][2]['product_start_indexes']
        );

        // Verify chunk 1 has labels 1 and 2
        $chunk1Indexes = $chunk1Products->pluck('label_index')->toArray();
        $this->assertEquals([1, 2], $chunk1Indexes, 'Chunk 1 should have labels 1 and 2');

        // Verify chunk 2 has labels 3 and 4
        $chunk2Indexes = $chunk2Products->pluck('label_index')->toArray();
        $this->assertEquals([3, 4], $chunk2Indexes, 'Chunk 2 should have labels 3 and 4');

        // Verify chunk 3 has label 5
        $chunk3Indexes = $chunk3Products->pluck('label_index')->toArray();
        $this->assertEquals([5], $chunk3Indexes, 'Chunk 3 should have label 5');
    }

    /**
     * Test 2: Counter should reset when product changes
     *
     * SCENARIO:
     * - Product A (GOHAN POLLO) with quantity 3
     * - Product B (GOHAN CAMARON) with quantity 2
     *
     * EXPECTED BEHAVIOR (the bug - counter should reset but doesn't):
     * - Product A labels: 1, 2, 3
     * - Product B labels: 1, 2 (counter should reset)
     *
     * CURRENT BUG (what actually happens):
     * - Product A labels: 1, 2, 3
     * - Product B labels: 4, 5 (counter continues globally)
     *
     * This test documents the expected behavior. It will FAIL until the bug is fixed.
     */
    public function test_counter_resets_when_product_changes(): void
    {
        // Create two products
        $productA = $this->createProductWithNutritionalInfo('GOHAN POLLO TEMPURA', 'GOH-001');
        $productB = $this->createProductWithNutritionalInfo('GOHAN CAMARON ORIENTAL', 'GOH-002');

        // Prepare data with quantities
        $quantities = [
            $productA->id => 3,
            $productB->id => 2,
        ];

        // Use large chunk size to keep all in one chunk
        $preparedData = $this->preparer->prepareData(
            [$productA->id, $productB->id],
            $quantities,
            100
        );

        // Get expanded products using product_start_indexes
        $products = $this->preparer->getExpandedProducts(
            $preparedData['chunks'][0]['product_ids'],
            $preparedData['chunks'][0]['quantities'],
            $preparedData['chunks'][0]['product_start_indexes']
        );

        // Separate by product
        $productALabels = $products->filter(fn($p) => $p->id === $productA->id);
        $productBLabels = $products->filter(fn($p) => $p->id === $productB->id);

        // Verify Product A has labels 1, 2, 3
        $productAIndexes = $productALabels->pluck('label_index')->values()->toArray();
        $this->assertEquals([1, 2, 3], $productAIndexes, 'Product A should have labels 1, 2, 3');

        // Verify Product B has labels 1, 2 (counter should reset)
        // This assertion documents the EXPECTED behavior
        // Currently it will FAIL because Product B gets labels 4, 5 (global counter)
        $productBIndexes = $productBLabels->pluck('label_index')->values()->toArray();
        $this->assertEquals([1, 2], $productBIndexes, 'Product B should have labels 1, 2 (counter should reset for new product)');
    }

    /**
     * Test 3: Product split across chunks should continue sequence correctly
     *
     * PRODUCTION BUG SCENARIO (OP-147 CUARTO CALIENTE):
     * - Product 1813 (PASTA ALFREDO SUPREMA) has 20 labels total
     * - Chunk size is 100
     * - Chunk 1 ends with 8 labels of product 1813 (labels 1-8)
     * - Chunk 2 starts with 12 labels of product 1813 (should be labels 9-20)
     *
     * ACTUAL BUG:
     * - Chunk 1: product 1813 labels 1-8 ✓
     * - Chunk 2: product 1813 labels 101-112 ✗ (uses global start_index instead of continuing)
     *
     * EXPECTED BEHAVIOR:
     * - Chunk 1: product 1813 labels 1-8
     * - Chunk 2: product 1813 labels 9-20 (continues from where chunk 1 left off)
     *
     * This test will FAIL until the bug is fixed.
     */
    public function test_product_split_across_chunks_continues_sequence(): void
    {
        // Create products to simulate the production scenario
        // We need enough products to fill chunk 1 and have product 1813 split
        $productA = $this->createProductWithNutritionalInfo('PRODUCT A', 'PROD-A');
        $productB = $this->createProductWithNutritionalInfo('PRODUCT B (SPLIT)', 'PROD-B');
        $productC = $this->createProductWithNutritionalInfo('PRODUCT C', 'PROD-C');

        // Quantities designed to split product B across chunks:
        // - Product A: 92 labels (fills most of chunk 1)
        // - Product B: 20 labels (8 in chunk 1, 12 in chunk 2)
        // - Product C: 5 labels (all in chunk 2)
        // Total: 117 labels = chunk 1 (100) + chunk 2 (17)
        $quantities = [
            $productA->id => 92,
            $productB->id => 20,
            $productC->id => 5,
        ];

        // Prepare data with chunk size of 100
        $preparedData = $this->preparer->prepareData(
            [$productA->id, $productB->id, $productC->id],
            $quantities,
            100
        );

        // Should have 2 chunks
        $this->assertCount(2, $preparedData['chunks'], 'Should have 2 chunks for 117 labels with chunk size 100');

        // Chunk 1: 100 labels (92 of A + 8 of B)
        // Chunk 2: 17 labels (12 of B + 5 of C)
        $this->assertEquals(100, $preparedData['chunks'][0]['label_count'], 'Chunk 1 should have 100 labels');
        $this->assertEquals(17, $preparedData['chunks'][1]['label_count'], 'Chunk 2 should have 17 labels');

        // Get expanded products for each chunk using product_start_indexes
        $chunk1Products = $this->preparer->getExpandedProducts(
            $preparedData['chunks'][0]['product_ids'],
            $preparedData['chunks'][0]['quantities'],
            $preparedData['chunks'][0]['product_start_indexes']
        );

        $chunk2Products = $this->preparer->getExpandedProducts(
            $preparedData['chunks'][1]['product_ids'],
            $preparedData['chunks'][1]['quantities'],
            $preparedData['chunks'][1]['product_start_indexes']
        );

        // Verify chunk 1 - Product A should have labels 1-92
        $chunk1ProductA = $chunk1Products->filter(fn($p) => $p->id === $productA->id);
        $chunk1ProductAIndexes = $chunk1ProductA->pluck('label_index')->values()->toArray();
        $this->assertEquals(range(1, 92), $chunk1ProductAIndexes, 'Chunk 1: Product A should have labels 1-92');

        // Verify chunk 1 - Product B should have labels 1-8 (first 8 of 20)
        $chunk1ProductB = $chunk1Products->filter(fn($p) => $p->id === $productB->id);
        $chunk1ProductBIndexes = $chunk1ProductB->pluck('label_index')->values()->toArray();
        $this->assertEquals(range(1, 8), $chunk1ProductBIndexes, 'Chunk 1: Product B should have labels 1-8');

        // CRITICAL TEST - Verify chunk 2 - Product B should CONTINUE from 9-20
        // This is the bug: currently it starts at 101 instead of 9
        $chunk2ProductB = $chunk2Products->filter(fn($p) => $p->id === $productB->id);
        $chunk2ProductBIndexes = $chunk2ProductB->pluck('label_index')->values()->toArray();
        $this->assertEquals(
            range(9, 20),
            $chunk2ProductBIndexes,
            'Chunk 2: Product B should CONTINUE with labels 9-20 (not restart at 101)'
        );

        // Verify chunk 2 - Product C should have labels 1-5 (new product, counter resets)
        $chunk2ProductC = $chunk2Products->filter(fn($p) => $p->id === $productC->id);
        $chunk2ProductCIndexes = $chunk2ProductC->pluck('label_index')->values()->toArray();
        $this->assertEquals(range(1, 5), $chunk2ProductCIndexes, 'Chunk 2: Product C should have labels 1-5 (counter resets for new product)');
    }
}