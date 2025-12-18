<?php

namespace Tests\Feature\Exports;

use App\Contracts\HorecaLabelDataRepositoryInterface;
use App\Jobs\GenerateHorecaLabelsJob;
use App\Models\AdvanceOrder;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PlatedDish;
use App\Models\PlatedDishIngredient;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Repositories\HorecaInformationRepository;
use App\Services\Labels\Generators\HorecaLabelGenerator;
use App\Services\Labels\HorecaLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use ReflectionMethod;
use Tests\TestCase;

/**
 * HorecaLabelService Integration Test
 *
 * Tests the complete flow of shelf_life data from database to label generation.
 *
 * PRODUCTION BUG SCENARIO:
 * - Products are imported with shelf_life of 3 days
 * - The system shows 3 days correctly in the database
 * - When generating labels, the expiration date equals the elaboration date
 * - Example: elaboration 18-12, expiration 18-12 (should be 21-12)
 *
 * ROOT CAUSE:
 * The shelf_life field was not being passed through expandLabelsWithWeights().
 *
 * DATA FLOW:
 * 1. HorecaLabelDataRepository::getHorecaLabelDataByAdvanceOrder() - gets shelf_life ✓
 * 2. HorecaLabelService::expandLabelsWithWeights() - should pass shelf_life ✓ (FIXED)
 * 3. GenerateHorecaLabelsJob - receives label data with shelf_life
 * 4. HorecaLabelGenerator::generateLabelHtml() - calls getExpirationDate($product)
 * 5. HorecaInformationRepository::getExpirationDate() - uses $product['shelf_life']
 *
 * This test validates the ENTIRE pipeline produces correct expiration dates.
 */
class HorecaLabelServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private HorecaLabelService $service;
    private HorecaLabelDataRepositoryInterface $repository;
    private HorecaInformationRepository $horecaInfoRepository;
    private Category $category;
    private Company $company;
    private Branch $branch;
    private User $user;
    private PriceList $priceList;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(HorecaLabelService::class);
        $this->repository = app(HorecaLabelDataRepositoryInterface::class);
        $this->horecaInfoRepository = app(HorecaInformationRepository::class);

        // Create base data
        $this->category = Category::create([
            'name' => 'HORECA TEST',
            'description' => 'Test category for HORECA labels',
        ]);

        $this->priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => true,
        ]);

        $this->company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'email' => 'test@company.com',
            'active' => true,
            'fantasy_name' => 'TEST COMPANY',
            'price_list_id' => $this->priceList->id,
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'fantasy_name' => 'Sucursal Test',
            'address' => 'Test Address',
            'min_price_order' => 0,
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test.user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    /**
     * Helper to create a HORECA product with plated dish and ingredients
     */
    private function createHorecaProductWithIngredient(
        string $productName,
        string $productCode,
        string $ingredientName,
        int $shelfLife,
        float $quantity = 300,
        float $maxQuantityHoreca = 1000
    ): array {
        $product = Product::create([
            'name' => $productName,
            'code' => $productCode,
            'description' => "Test HORECA product {$productName}",
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        $ingredient = PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => $ingredientName,
            'measure_unit' => 'GR',
            'quantity' => $quantity,
            'max_quantity_horeca' => $maxQuantityHoreca,
            'shelf_life' => $shelfLife,
            'order_index' => 0,
        ]);

        return [
            'product' => $product,
            'platedDish' => $platedDish,
            'ingredient' => $ingredient,
        ];
    }

    /**
     * Test 1: Verify shelf_life flows from Repository through Service expandLabelsWithWeights
     *
     * This test validates that HorecaLabelDataRepository returns shelf_life
     * and that HorecaLabelService::expandLabelsWithWeights() preserves it.
     */
    public function test_shelf_life_flows_from_repository_through_expand_labels(): void
    {
        // Create HORECA product with shelf_life = 5 days
        $horecaData = $this->createHorecaProductWithIngredient(
            'ACM - HORECA BOWL TEST',
            'ACM-BOWL-TEST',
            'MZC - CONSOME DE POLLO GRANEL',
            5 // shelf_life = 5 days
        );

        // Create order
        $order = Order::create([
            'user_id' => $this->user->id,
            'total' => 10000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $horecaData['product']->id,
            'quantity' => 2,
            'unit_price' => 5000,
        ]);

        // Step 1: Get data from repository (simulating what service does)
        $labelData = $this->repository->getHorecaLabelDataByOrders([$order->id]);

        // Verify repository returns shelf_life
        $this->assertCount(1, $labelData, 'Should have 1 label group');
        $this->assertArrayHasKey('shelf_life', $labelData->first(), 'Repository MUST return shelf_life');
        $this->assertEquals(5, $labelData->first()['shelf_life'], 'Repository shelf_life should be 5 days');

        // Step 2: Expand labels through service (using reflection)
        $method = new ReflectionMethod(HorecaLabelService::class, 'expandLabelsWithWeights');
        $method->setAccessible(true);
        $expandedLabels = $method->invoke($this->service, $labelData);

        // Verify expanded labels preserve shelf_life
        $this->assertCount(1, $expandedLabels, 'Should have 1 expanded label');
        $expandedLabel = $expandedLabels->first();

        $this->assertArrayHasKey(
            'shelf_life',
            $expandedLabel,
            'Expanded label MUST contain shelf_life key'
        );
        $this->assertEquals(
            5,
            $expandedLabel['shelf_life'],
            'Expanded label shelf_life should be 5 days'
        );
    }

    /**
     * Test 2: Verify shelf_life reaches HorecaInformationRepository::getExpirationDate
     *
     * This test validates that the expanded label data (with shelf_life) produces
     * the correct expiration date when passed to getExpirationDate().
     */
    public function test_shelf_life_produces_correct_expiration_date(): void
    {
        // Simulate expanded label data (as it would come from expandLabelsWithWeights)
        $expandedLabel = [
            'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
            'ingredient_product_code' => 'MZC',
            'grouper_name' => 'Sucursal Test',
            'branch_fantasy_name' => 'Sucursal Test',
            'product_id' => 1,
            'product_name' => 'ACM - HORECA BOWL TEST',
            'measure_unit' => 'GR',
            'shelf_life' => 3, // 3 days shelf life
            'net_weight' => 600,
        ];

        $elaborationDate = '18/12/2025';

        // Call getExpirationDate (this is what HorecaLabelGenerator does)
        $expirationDate = $this->horecaInfoRepository->getExpirationDate($expandedLabel, $elaborationDate);

        // Verify correct expiration date calculation
        $this->assertEquals(
            '21/12/2025',
            $expirationDate,
            'Expiration date should be elaboration + 3 days (18/12 + 3 = 21/12)'
        );
    }

    /**
     * Test 3: Verify null shelf_life returns same date (elaboration = expiration)
     *
     * When shelf_life is null, the expiration date should equal the elaboration date.
     * This is the expected behavior for products without defined shelf life.
     */
    public function test_null_shelf_life_returns_elaboration_date_as_expiration(): void
    {
        $expandedLabel = [
            'ingredient_name' => 'TEST INGREDIENT',
            'shelf_life' => null, // No shelf life defined
            'net_weight' => 500,
        ];

        $elaborationDate = '18/12/2025';

        $expirationDate = $this->horecaInfoRepository->getExpirationDate($expandedLabel, $elaborationDate);

        $this->assertEquals(
            '18/12/2025',
            $expirationDate,
            'When shelf_life is null, expiration should equal elaboration'
        );
    }

    /**
     * Test 4: Verify missing shelf_life key returns elaboration date
     *
     * THIS IS THE BUG SCENARIO: When shelf_life key is missing from the array,
     * getExpirationDate returns elaboration date (wrong behavior for products WITH shelf_life).
     *
     * This test documents what happens when the bug exists (shelf_life not passed).
     */
    public function test_missing_shelf_life_key_returns_elaboration_date(): void
    {
        // Label WITHOUT shelf_life key (simulates the bug)
        $expandedLabelWithoutShelfLife = [
            'ingredient_name' => 'TEST INGREDIENT',
            // 'shelf_life' => 3,  <-- MISSING! This is the bug
            'net_weight' => 500,
        ];

        $elaborationDate = '18/12/2025';

        $expirationDate = $this->horecaInfoRepository->getExpirationDate(
            $expandedLabelWithoutShelfLife,
            $elaborationDate
        );

        // This documents the BUGGY behavior
        $this->assertEquals(
            '18/12/2025',
            $expirationDate,
            'BUG: Missing shelf_life causes expiration = elaboration'
        );
    }

    /**
     * Test 5: Complete integration flow - Job receives correct shelf_life data
     *
     * This test validates that when the Service dispatches a Job, the Job
     * receives label data with shelf_life correctly populated.
     *
     * Uses OrderRepository::createAdvanceOrderFromOrders() to properly create
     * AdvanceOrder with all required pivot table relationships.
     */
    public function test_job_receives_label_data_with_shelf_life(): void
    {
        // Create production area
        $productionArea = \App\Models\ProductionArea::create([
            'name' => 'EMPLATADO TEST',
            'description' => 'Test production area',
        ]);

        // Create HORECA product with shelf_life = 7 days
        $horecaData = $this->createHorecaProductWithIngredient(
            'ACM - HORECA ENSALADA TEST',
            'ACM-ENS-TEST',
            'ENS - ENSALADA MIXTA',
            7 // shelf_life = 7 days
        );

        // Attach product to production area
        $horecaData['product']->productionAreas()->attach($productionArea->id);

        // Create order with PROCESSED status and dispatch_date
        $dispatchDate = now()->addDays(1)->toDateString();
        $order = Order::create([
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
            'total' => 15000,
            'status' => \App\Enums\OrderStatus::PROCESSED,
            'dispatch_date' => $dispatchDate,
            'date' => $dispatchDate,
            'order_number' => 'ORD-TEST-SHELF-LIFE',
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $horecaData['product']->id,
            'quantity' => 3,
            'unit_price' => 5000,
        ]);

        // Create AdvanceOrder using the proper repository method
        $orderRepository = app(\App\Repositories\OrderRepository::class);
        $advanceOrder = $orderRepository->createAdvanceOrderFromOrders(
            [$order->id],
            now()->addDays(1)->format('Y-m-d H:i:s'),
            [$productionArea->id]
        );

        // Verify AdvanceOrder was created with associated order lines
        $aoLines = $advanceOrder->associatedOrderLines()->with('orderLine.product')->get();
        $this->assertGreaterThan(0, $aoLines->count(), 'AdvanceOrder should have associated order lines');

        // Fake the Bus to capture dispatched jobs
        Bus::fake();

        // Call the service
        $this->service->generateLabelsForAdvanceOrder($advanceOrder->id, '18/12/2025');

        // Verify job was dispatched with correct data
        Bus::assertDispatched(GenerateHorecaLabelsJob::class, function ($job) {
            // Use reflection to get the private labelData property
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('labelData');
            $property->setAccessible(true);
            $labelData = $property->getValue($job);

            // Verify label data contains shelf_life
            $this->assertNotEmpty($labelData, 'Job should have label data');

            $firstLabel = $labelData[0];

            // CRITICAL: shelf_life must be present in the job's label data
            $this->assertArrayHasKey(
                'shelf_life',
                $firstLabel,
                'Job labelData MUST contain shelf_life key'
            );

            $this->assertEquals(
                7,
                $firstLabel['shelf_life'],
                'Job labelData shelf_life should be 7 days'
            );

            return true;
        });
    }

    /**
     * Test 6: Multiple ingredients with different shelf_life values
     *
     * Validates that when a product has multiple ingredients with different
     * shelf_life values, each expanded label preserves its correct shelf_life.
     */
    public function test_multiple_ingredients_preserve_individual_shelf_life(): void
    {
        // Create product
        $product = Product::create([
            'name' => 'ACM - HORECA COMBO TEST',
            'code' => 'ACM-COMBO-TEST',
            'description' => 'Test combo with multiple ingredients',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // Ingredient 1: shelf_life = 3 days
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 300,
            'max_quantity_horeca' => 1000,
            'shelf_life' => 3,
            'order_index' => 0,
        ]);

        // Ingredient 2: shelf_life = 7 days
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ARR - ARROZ BLANCO',
            'measure_unit' => 'GR',
            'quantity' => 150,
            'max_quantity_horeca' => 1000,
            'shelf_life' => 7,
            'order_index' => 1,
        ]);

        // Ingredient 3: shelf_life = 1 day
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'JUG - JUGO DE NARANJA',
            'measure_unit' => 'ML',
            'quantity' => 200,
            'max_quantity_horeca' => 500,
            'shelf_life' => 1,
            'order_index' => 2,
        ]);

        // Create order
        $order = Order::create([
            'user_id' => $this->user->id,
            'total' => 20000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 10000,
        ]);

        // Get label data from repository
        $labelData = $this->repository->getHorecaLabelDataByOrders([$order->id]);

        $this->assertCount(3, $labelData, 'Should have 3 label groups (one per ingredient)');

        // Verify each ingredient has correct shelf_life
        $mzcLabel = $labelData->firstWhere('ingredient_name', 'MZC - CONSOME DE POLLO GRANEL');
        $arrLabel = $labelData->firstWhere('ingredient_name', 'ARR - ARROZ BLANCO');
        $jugLabel = $labelData->firstWhere('ingredient_name', 'JUG - JUGO DE NARANJA');

        $this->assertEquals(3, $mzcLabel['shelf_life'], 'MZC should have shelf_life = 3 days');
        $this->assertEquals(7, $arrLabel['shelf_life'], 'ARR should have shelf_life = 7 days');
        $this->assertEquals(1, $jugLabel['shelf_life'], 'JUG should have shelf_life = 1 day');

        // Expand labels and verify shelf_life is preserved
        $method = new ReflectionMethod(HorecaLabelService::class, 'expandLabelsWithWeights');
        $method->setAccessible(true);
        $expandedLabels = $method->invoke($this->service, $labelData);

        $expandedMzc = $expandedLabels->firstWhere('ingredient_name', 'MZC - CONSOME DE POLLO GRANEL');
        $expandedArr = $expandedLabels->firstWhere('ingredient_name', 'ARR - ARROZ BLANCO');
        $expandedJug = $expandedLabels->firstWhere('ingredient_name', 'JUG - JUGO DE NARANJA');

        $this->assertEquals(3, $expandedMzc['shelf_life'], 'Expanded MZC should preserve shelf_life = 3');
        $this->assertEquals(7, $expandedArr['shelf_life'], 'Expanded ARR should preserve shelf_life = 7');
        $this->assertEquals(1, $expandedJug['shelf_life'], 'Expanded JUG should preserve shelf_life = 1');

        // Verify expiration dates
        $elaboration = '18/12/2025';

        $mzcExpiration = $this->horecaInfoRepository->getExpirationDate($expandedMzc, $elaboration);
        $arrExpiration = $this->horecaInfoRepository->getExpirationDate($expandedArr, $elaboration);
        $jugExpiration = $this->horecaInfoRepository->getExpirationDate($expandedJug, $elaboration);

        $this->assertEquals('21/12/2025', $mzcExpiration, 'MZC: 18/12 + 3 days = 21/12');
        $this->assertEquals('25/12/2025', $arrExpiration, 'ARR: 18/12 + 7 days = 25/12');
        $this->assertEquals('19/12/2025', $jugExpiration, 'JUG: 18/12 + 1 day = 19/12');
    }
}
