<?php

namespace Tests\Unit\Repositories;

use App\Contracts\HorecaLabelDataRepositoryInterface;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PlatedDish;
use App\Models\PlatedDishIngredient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test HORECA Label Data Repository
 *
 * Tests the grouping logic for HORECA label generation based on orders.
 *
 * BUSINESS LOGIC (from plan_desarrollo_etiquetas_horeca.md):
 * 1. Group by branch (order lines with same branch)
 * 2. Group by ingredient (product -> plated dish -> ingredients)
 * 3. Calculate total quantity needed per ingredient per branch
 * 4. Split labels by max_quantity_horeca if total exceeds max
 *
 * SCENARIOS TESTED:
 * 1. Scenario 1: Total quantity ≤ max_quantity_horeca (single label)
 *    - Branch A: 2 products × 300 GR = 600 GR, max: 1000 GR → 1 label [600 GR]
 *    - Branch B: 2 products × 300 GR = 600 GR, max: 1000 GR → 1 label [600 GR]
 *
 * 2. Scenario 2: Total quantity > max_quantity_horeca (multiple labels)
 *    - Branch A: 5 products × 300 GR = 1500 GR, max: 1000 GR → 2 labels [1000 GR, 500 GR]
 *    - Branch B: 4 products × 300 GR = 1200 GR, max: 1000 GR → 2 labels [1000 GR, 200 GR]
 */
class HorecaLabelDataRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private HorecaLabelDataRepositoryInterface $repository;
    private Category $category;
    private Company $companyA;
    private Branch $branchA;
    private Branch $branchB;
    private User $userBranchA;
    private User $userBranchB;
    private Product $horecaProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(HorecaLabelDataRepositoryInterface::class);

        // Create category (required for products)
        $this->category = Category::create([
            'name' => 'HORECA',
            'description' => 'HORECA products',
        ]);

        // Create company
        $this->companyA = Company::create([
            'name' => 'TEST COMPANY A S.A.',
            'email' => 'company.a@test.com',
            'active' => true,
            'fantasy_name' => 'TEST COMPANY A',
        ]);

        // Create branches with fantasy names
        $this->branchA = Branch::create([
            'company_id' => $this->companyA->id,
            'fantasy_name' => 'Sucursal Centro',
            'address' => 'Address A',
            'min_price_order' => 0,
        ]);

        $this->branchB = Branch::create([
            'company_id' => $this->companyA->id,
            'fantasy_name' => 'Sucursal Oriente',
            'address' => 'Address B',
            'min_price_order' => 0,
        ]);

        // Create users for each branch
        $this->userBranchA = User::create([
            'name' => 'User Branch A',
            'email' => 'user.a@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA->id,
        ]);

        $this->userBranchB = User::create([
            'name' => 'User Branch B',
            'email' => 'user.b@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchB->id,
        ]);

        // Create shared HORECA product for branch grouping test
        $this->horecaProduct = Product::create([
            'name' => 'HORECA SHARED PRODUCT',
            'code' => 'ACM-HORECA-SHARED',
            'description' => 'Shared HORECA product for multi-branch test',
            'category_id' => $this->category->id,
            'price' => 5000,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
    }

    /**
     * Test Scenario 1: Total quantity ≤ max_quantity_horeca
     *
     * SETUP:
     * - Product: ACM - HORECA CONSOME DE POLLO INDIVIDUAL
     * - Ingredient: MZC - CONSOME DE POLLO GRANEL (300 GR per dish, max: 1000 GR)
     * - Branch A: 2 dishes ordered
     * - Branch B: 2 dishes ordered
     *
     * EXPECTED RESULT:
     * - Branch A: 2 × 300 = 600 GR → 1 label [600 GR]
     * - Branch B: 2 × 300 = 600 GR → 1 label [600 GR]
     * - Total: 2 label groups
     */
    public function test_scenario_1_single_label_per_branch_when_total_quantity_within_max(): void
    {
        // Create HORECA product
        $horecaProduct = Product::create([
            'name' => 'HORECA CONSOME DE POLLO INDIVIDUAL',
            'code' => 'ACM-HORECA-POLLO',
            'description' => 'Individual chicken consommé for HORECA',
            'category_id' => $this->category->id,
            'price' => 5000,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create plated dish for this product
        $platedDish = PlatedDish::create([
            'product_id' => $horecaProduct->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // Create ingredient: 300 GR per dish, max 1000 GR per label
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 300,
            'max_quantity_horeca' => 1000,
            'order_index' => 0,
        ]);

        // Create order for Branch A with 2 dishes
        $orderA = Order::create([
            'user_id' => $this->userBranchA->id,
            'total' => 10000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderA->id,
            'product_id' => $horecaProduct->id,
            'quantity' => 2, // 2 dishes
            'unit_price' => 5000,
        ]);

        // Create order for Branch B with 2 dishes
        $orderB = Order::create([
            'user_id' => $this->userBranchB->id,
            'total' => 10000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderB->id,
            'product_id' => $horecaProduct->id,
            'quantity' => 2, // 2 dishes
            'unit_price' => 5000,
        ]);

        // Execute repository method
        $labelData = $this->repository->getHorecaLabelDataByOrders([$orderA->id, $orderB->id]);

        // ===== ASSERTIONS =====

        // Should have 2 label groups (one per branch)
        $this->assertCount(2, $labelData, 'Should have 2 label groups (one per branch)');

        // Get label data for each branch
        $labelBranchA = $labelData->firstWhere('branch_id', $this->branchA->id);
        $labelBranchB = $labelData->firstWhere('branch_id', $this->branchB->id);

        // Verify Branch A label data
        $this->assertNotNull($labelBranchA, 'Should have label data for Branch A');
        $this->assertEquals('MZC - CONSOME DE POLLO GRANEL', $labelBranchA['ingredient_name']);
        $this->assertEquals('MZC', $labelBranchA['ingredient_product_code']);
        $this->assertEquals('Sucursal Centro', $labelBranchA['branch_fantasy_name']);
        $this->assertEquals('GR', $labelBranchA['measure_unit']);
        $this->assertEquals(600, $labelBranchA['total_quantity_needed'], 'Branch A: 2 dishes × 300 GR = 600 GR');
        $this->assertEquals(1000, $labelBranchA['max_quantity_horeca']);
        $this->assertEquals(1, $labelBranchA['labels_count'], 'Branch A: should have 1 label');
        $this->assertEquals([600], $labelBranchA['weights'], 'Branch A: single label with 600 GR');

        // Verify Branch B label data
        $this->assertNotNull($labelBranchB, 'Should have label data for Branch B');
        $this->assertEquals('MZC - CONSOME DE POLLO GRANEL', $labelBranchB['ingredient_name']);
        $this->assertEquals('MZC', $labelBranchB['ingredient_product_code']);
        $this->assertEquals('Sucursal Oriente', $labelBranchB['branch_fantasy_name']);
        $this->assertEquals('GR', $labelBranchB['measure_unit']);
        $this->assertEquals(600, $labelBranchB['total_quantity_needed'], 'Branch B: 2 dishes × 300 GR = 600 GR');
        $this->assertEquals(1000, $labelBranchB['max_quantity_horeca']);
        $this->assertEquals(1, $labelBranchB['labels_count'], 'Branch B: should have 1 label');
        $this->assertEquals([600], $labelBranchB['weights'], 'Branch B: single label with 600 GR');
    }

    /**
     * Test Scenario 2: Total quantity > max_quantity_horeca
     *
     * SETUP:
     * - Product: ACM - HORECA CONSOME DE POLLO INDIVIDUAL
     * - Ingredient: MZC - CONSOME DE POLLO GRANEL (300 GR per dish, max: 1000 GR)
     * - Branch A: 5 dishes ordered
     * - Branch B: 4 dishes ordered
     *
     * EXPECTED RESULT:
     * - Branch A: 5 × 300 = 1500 GR → 2 labels [1000 GR, 500 GR]
     * - Branch B: 4 × 300 = 1200 GR → 2 labels [1000 GR, 200 GR]
     * - Total: 2 label groups
     */
    public function test_scenario_2_multiple_labels_per_branch_when_total_quantity_exceeds_max(): void
    {
        // Create HORECA product
        $horecaProduct = Product::create([
            'name' => 'HORECA CONSOME DE POLLO INDIVIDUAL',
            'code' => 'ACM-HORECA-POLLO',
            'description' => 'Individual chicken consommé for HORECA',
            'category_id' => $this->category->id,
            'price' => 5000,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create plated dish for this product
        $platedDish = PlatedDish::create([
            'product_id' => $horecaProduct->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // Create ingredient: 300 GR per dish, max 1000 GR per label
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 300,
            'max_quantity_horeca' => 1000,
            'order_index' => 0,
        ]);

        // Create order for Branch A with 5 dishes
        $orderA = Order::create([
            'user_id' => $this->userBranchA->id,
            'total' => 25000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderA->id,
            'product_id' => $horecaProduct->id,
            'quantity' => 5, // 5 dishes
            'unit_price' => 5000,
        ]);

        // Create order for Branch B with 4 dishes
        $orderB = Order::create([
            'user_id' => $this->userBranchB->id,
            'total' => 20000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderB->id,
            'product_id' => $horecaProduct->id,
            'quantity' => 4, // 4 dishes
            'unit_price' => 5000,
        ]);

        // Execute repository method
        $labelData = $this->repository->getHorecaLabelDataByOrders([$orderA->id, $orderB->id]);

        // ===== ASSERTIONS =====

        // Should have 2 label groups (one per branch)
        $this->assertCount(2, $labelData, 'Should have 2 label groups (one per branch)');

        // Get label data for each branch
        $labelBranchA = $labelData->firstWhere('branch_id', $this->branchA->id);
        $labelBranchB = $labelData->firstWhere('branch_id', $this->branchB->id);

        // Verify Branch A label data
        $this->assertNotNull($labelBranchA, 'Should have label data for Branch A');
        $this->assertEquals('MZC - CONSOME DE POLLO GRANEL', $labelBranchA['ingredient_name']);
        $this->assertEquals('MZC', $labelBranchA['ingredient_product_code']);
        $this->assertEquals('Sucursal Centro', $labelBranchA['branch_fantasy_name']);
        $this->assertEquals('GR', $labelBranchA['measure_unit']);
        $this->assertEquals(1500, $labelBranchA['total_quantity_needed'], 'Branch A: 5 dishes × 300 GR = 1500 GR');
        $this->assertEquals(1000, $labelBranchA['max_quantity_horeca']);
        $this->assertEquals(2, $labelBranchA['labels_count'], 'Branch A: should have 2 labels');
        $this->assertEquals([1000, 500], $labelBranchA['weights'], 'Branch A: first label 1000 GR, second label 500 GR');

        // Verify Branch B label data
        $this->assertNotNull($labelBranchB, 'Should have label data for Branch B');
        $this->assertEquals('MZC - CONSOME DE POLLO GRANEL', $labelBranchB['ingredient_name']);
        $this->assertEquals('MZC', $labelBranchB['ingredient_product_code']);
        $this->assertEquals('Sucursal Oriente', $labelBranchB['branch_fantasy_name']);
        $this->assertEquals('GR', $labelBranchB['measure_unit']);
        $this->assertEquals(1200, $labelBranchB['total_quantity_needed'], 'Branch B: 4 dishes × 300 GR = 1200 GR');
        $this->assertEquals(1000, $labelBranchB['max_quantity_horeca']);
        $this->assertEquals(2, $labelBranchB['labels_count'], 'Branch B: should have 2 labels');
        $this->assertEquals([1000, 200], $labelBranchB['weights'], 'Branch B: first label 1000 GR, second label 200 GR');
    }

    /**
     * Test multiple ingredients per product
     *
     * SETUP:
     * - Product with 2 ingredients:
     *   - Ingredient 1: 300 GR per dish, max 1000 GR
     *   - Ingredient 2: 50 ML per dish, max 500 ML
     * - Branch A: 4 dishes ordered
     *
     * EXPECTED RESULT:
     * - 2 label groups (one per ingredient)
     * - Ingredient 1: 4 × 300 = 1200 GR → 2 labels [1000 GR, 200 GR]
     * - Ingredient 2: 4 × 50 = 200 ML → 1 label [200 ML]
     */
    public function test_multiple_ingredients_per_product(): void
    {
        // Create HORECA product
        $horecaProduct = Product::create([
            'name' => 'HORECA COMBO DISH',
            'code' => 'ACM-HORECA-COMBO',
            'description' => 'Combo dish with multiple ingredients',
            'category_id' => $this->category->id,
            'price' => 8000,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create plated dish
        $platedDish = PlatedDish::create([
            'product_id' => $horecaProduct->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // Create ingredient 1: Solid (GR)
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 300,
            'max_quantity_horeca' => 1000,
            'order_index' => 0,
        ]);

        // Create ingredient 2: Liquid (ML)
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'JUG - JUGO NARANJA NATURAL',
            'measure_unit' => 'ML',
            'quantity' => 50,
            'max_quantity_horeca' => 500,
            'order_index' => 1,
        ]);

        // Create order for Branch A with 4 dishes
        $orderA = Order::create([
            'user_id' => $this->userBranchA->id,
            'total' => 32000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderA->id,
            'product_id' => $horecaProduct->id,
            'quantity' => 4, // 4 dishes
            'unit_price' => 8000,
        ]);

        // Execute repository method
        $labelData = $this->repository->getHorecaLabelDataByOrders([$orderA->id]);

        // ===== ASSERTIONS =====

        // Should have 2 label groups (one per ingredient)
        $this->assertCount(2, $labelData, 'Should have 2 label groups (one per ingredient)');

        // Get label data for each ingredient
        $labelIngredient1 = $labelData->firstWhere('ingredient_name', 'MZC - CONSOME DE POLLO GRANEL');
        $labelIngredient2 = $labelData->firstWhere('ingredient_name', 'JUG - JUGO NARANJA NATURAL');

        // Verify Ingredient 1 (Solid)
        $this->assertNotNull($labelIngredient1, 'Should have label data for ingredient 1');
        $this->assertEquals('MZC', $labelIngredient1['ingredient_product_code']);
        $this->assertEquals('GR', $labelIngredient1['measure_unit']);
        $this->assertEquals(1200, $labelIngredient1['total_quantity_needed'], 'Ingredient 1: 4 dishes × 300 GR = 1200 GR');
        $this->assertEquals(2, $labelIngredient1['labels_count'], 'Ingredient 1: should have 2 labels');
        $this->assertEquals([1000, 200], $labelIngredient1['weights']);

        // Verify Ingredient 2 (Liquid)
        $this->assertNotNull($labelIngredient2, 'Should have label data for ingredient 2');
        $this->assertEquals('JUG', $labelIngredient2['ingredient_product_code']);
        $this->assertEquals('ML', $labelIngredient2['measure_unit']);
        $this->assertEquals(200, $labelIngredient2['total_quantity_needed'], 'Ingredient 2: 4 dishes × 50 ML = 200 ML');
        $this->assertEquals(1, $labelIngredient2['labels_count'], 'Ingredient 2: should have 1 label');
        $this->assertEquals([200], $labelIngredient2['weights']);
    }

    /**
     * Test that products without plated dishes or with plated dishes without ingredients are ignored
     *
     * SETUP:
     * - Product 1: HORECA product WITH plated dish and 1 ingredient (should be included)
     * - Product 2: Regular product WITHOUT plated dish (should be ignored)
     * - Product 3: HORECA product WITH plated dish but NO ingredients (should be ignored)
     * - Branch A: Order with all 3 products
     *
     * EXPECTED RESULT:
     * - Only 1 label group (from Product 1)
     * - Products 2 and 3 should be completely ignored
     */
    public function test_ignores_products_without_plated_dish_or_without_ingredients(): void
    {
        // ===== Product 1: HORECA with plated dish and ingredient (VALID) =====
        $horecaProductValid = Product::create([
            'name' => 'HORECA VALID PRODUCT',
            'code' => 'ACM-HORECA-VALID',
            'description' => 'HORECA product with ingredients',
            'category_id' => $this->category->id,
            'price' => 5000,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $platedDishValid = PlatedDish::create([
            'product_id' => $horecaProductValid->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishValid->id,
            'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 300,
            'max_quantity_horeca' => 1000,
            'order_index' => 0,
        ]);

        // ===== Product 2: Regular product WITHOUT plated dish (INVALID) =====
        $regularProductNoPlatedDish = Product::create([
            'name' => 'REGULAR PRODUCT NO PLATED DISH',
            'code' => 'REG-NO-PLATED',
            'description' => 'Regular product without plated dish',
            'category_id' => $this->category->id,
            'price' => 3000,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
        // NO PlatedDish created for this product

        // ===== Product 3: HORECA product WITH plated dish but NO ingredients (INVALID) =====
        $horecaProductNoIngredients = Product::create([
            'name' => 'HORECA PRODUCT NO INGREDIENTS',
            'code' => 'ACM-HORECA-NO-ING',
            'description' => 'HORECA product with plated dish but no ingredients',
            'category_id' => $this->category->id,
            'price' => 4000,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PlatedDish::create([
            'product_id' => $horecaProductNoIngredients->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);
        // NO ingredients created for this plated dish

        // ===== Create order with all 3 products =====
        $order = Order::create([
            'user_id' => $this->userBranchA->id,
            'total' => 12000,
            'status' => 'pending',
        ]);

        // Order line for valid HORECA product (2 dishes)
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $horecaProductValid->id,
            'quantity' => 2,
            'unit_price' => 5000,
        ]);

        // Order line for regular product WITHOUT plated dish
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $regularProductNoPlatedDish->id,
            'quantity' => 1,
            'unit_price' => 3000,
        ]);

        // Order line for HORECA product WITHOUT ingredients
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $horecaProductNoIngredients->id,
            'quantity' => 1,
            'unit_price' => 4000,
        ]);

        // ===== Execute repository method =====
        $labelData = $this->repository->getHorecaLabelDataByOrders([$order->id]);

        // ===== ASSERTIONS =====

        // Should have ONLY 1 label group (from Product 1 with ingredients)
        $this->assertCount(
            1,
            $labelData,
            'Should have exactly 1 label group (only from product with plated dish AND ingredients)'
        );

        // Verify the label is from the valid HORECA product
        $label = $labelData->first();
        $this->assertNotNull($label, 'Should have one label');
        $this->assertEquals('MZC - CONSOME DE POLLO GRANEL', $label['ingredient_name']);
        $this->assertEquals('Sucursal Centro', $label['branch_fantasy_name']);
        $this->assertEquals(600, $label['total_quantity_needed'], '2 dishes × 300 GR = 600 GR');
        $this->assertEquals(1, $label['labels_count']);
        $this->assertEquals([600], $label['weights']);

        // Verify products without plated dish or without ingredients were ignored
        $ingredientNames = $labelData->pluck('ingredient_name')->toArray();
        $this->assertCount(1, $ingredientNames, 'Should only have 1 unique ingredient');
        $this->assertNotContains('REGULAR PRODUCT NO PLATED DISH', $ingredientNames, 'Regular product should be ignored');
        $this->assertNotContains('HORECA PRODUCT NO INGREDIENTS', $ingredientNames, 'Product without ingredients should be ignored');
    }

    /**
     * Test that labels are grouped by branch (all labels from one branch first, then all from another)
     *
     * SETUP:
     * - Branch A (Sucursal Centro): 2 ingredients (MZC, JUG)
     * - Branch B (Sucursal Sur): 2 ingredients (MZC, ARR)
     * - Branch C (Sucursal Norte): 1 ingredient (MZC)
     *
     * EXPECTED ORDER:
     * 1. All Branch A labels (MZC, JUG)
     * 2. All Branch B labels (MZC, ARR)
     * 3. All Branch C labels (MZC)
     *
     * This ensures warehouse/kitchen can process one branch at a time
     */
    public function test_labels_are_grouped_by_branch(): void
    {
        // ===== Create additional company and branches =====
        $companyB = Company::create([
            'name' => 'TEST COMPANY B S.A.',
            'email' => 'company.b@test.com',
            'active' => true,
            'fantasy_name' => 'TEST COMPANY B',
        ]);

        $branchB = Branch::create([
            'company_id' => $companyB->id,
            'fantasy_name' => 'Sucursal Sur',
            'address' => 'Address B',
            'min_price_order' => 0,
        ]);

        $companyC = Company::create([
            'name' => 'TEST COMPANY C S.A.',
            'email' => 'company.c@test.com',
            'active' => true,
            'fantasy_name' => 'TEST COMPANY C',
        ]);

        $branchC = Branch::create([
            'company_id' => $companyC->id,
            'fantasy_name' => 'Sucursal Norte',
            'address' => 'Address C',
            'min_price_order' => 0,
        ]);

        // Create users for each branch
        $userBranchB = User::create([
            'name' => 'User Branch B',
            'email' => 'user.branch.b@test.com',
            'password' => bcrypt('password'),
            'branch_id' => $branchB->id,
            'company_id' => $companyB->id,
            'nickname' => 'user.branch.b',
        ]);

        $userBranchC = User::create([
            'name' => 'User Branch C',
            'email' => 'user.branch.c@test.com',
            'password' => bcrypt('password'),
            'branch_id' => $branchC->id,
            'company_id' => $companyC->id,
            'nickname' => 'user.branch.c',
        ]);

        // ===== Create 3 different ingredients =====
        // Ingredient 1: MZC - CONSOME (common to all branches)
        $platedDishMZC = PlatedDish::create([
            'product_id' => $this->horecaProduct->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishMZC->id,
            'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 100,
            'max_quantity_horeca' => 1000,
            'order_index' => 0,
        ]);

        // Ingredient 2: JUG - JUGO (only Branch A)
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishMZC->id,
            'ingredient_name' => 'JUG - JUGO DE NARANJA',
            'measure_unit' => 'ML',
            'quantity' => 50,
            'max_quantity_horeca' => 500,
            'order_index' => 1,
        ]);

        // Ingredient 3: ARR - ARROZ (only Branch B)
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishMZC->id,
            'ingredient_name' => 'ARR - ARROZ BLANCO',
            'measure_unit' => 'GR',
            'quantity' => 150,
            'max_quantity_horeca' => 1000,
            'order_index' => 2,
        ]);

        // ===== Create orders for each branch =====

        // Branch A: Order with MZC and JUG (2 dishes)
        $orderA = Order::create([
            'user_id' => $this->userBranchA->id,
            'total' => 10000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderA->id,
            'product_id' => $this->horecaProduct->id,
            'quantity' => 2,
            'unit_price' => 5000,
        ]);

        // Branch B: Order with MZC and ARR (3 dishes)
        $orderB = Order::create([
            'user_id' => $userBranchB->id,
            'total' => 15000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderB->id,
            'product_id' => $this->horecaProduct->id,
            'quantity' => 3,
            'unit_price' => 5000,
        ]);

        // Branch C: Order with only MZC (1 dish)
        $orderC = Order::create([
            'user_id' => $userBranchC->id,
            'total' => 5000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderC->id,
            'product_id' => $this->horecaProduct->id,
            'quantity' => 1,
            'unit_price' => 5000,
        ]);

        // ===== Execute repository method =====
        $labelData = $this->repository->getHorecaLabelDataByOrders([
            $orderA->id,
            $orderB->id,
            $orderC->id,
        ]);

        // ===== ASSERTIONS =====

        // Should have 9 label groups total:
        // Branch A: MZC, JUG, ARR (3 labels)
        // Branch B: MZC, JUG, ARR (3 labels)
        // Branch C: MZC, JUG, ARR (3 labels)
        $this->assertCount(9, $labelData, 'Should have 9 label groups (3 branches × 3 ingredients)');

        // Extract branch IDs in order
        $branchIdsInOrder = $labelData->pluck('branch_id')->toArray();

        // Group consecutive same branch IDs
        $branchGroups = [];
        $currentBranch = null;
        $currentGroup = [];

        foreach ($branchIdsInOrder as $branchId) {
            if ($branchId !== $currentBranch) {
                if (!empty($currentGroup)) {
                    $branchGroups[] = $currentGroup;
                }
                $currentBranch = $branchId;
                $currentGroup = [$branchId];
            } else {
                $currentGroup[] = $branchId;
            }
        }

        if (!empty($currentGroup)) {
            $branchGroups[] = $currentGroup;
        }

        // Assert that we have exactly 3 groups (one per branch)
        $this->assertCount(
            3,
            $branchGroups,
            'Labels should be grouped by branch (3 groups: Branch A, Branch B, Branch C)'
        );

        // Verify each group contains only one unique branch ID
        foreach ($branchGroups as $index => $group) {
            $uniqueBranches = array_unique($group);
            $this->assertCount(
                1,
                $uniqueBranches,
                "Group {$index} should contain only one branch ID"
            );
        }

        // Verify the content of each branch group
        $branchALabels = $labelData->where('branch_fantasy_name', 'Sucursal Centro');
        $branchBLabels = $labelData->where('branch_fantasy_name', 'Sucursal Sur');
        $branchCLabels = $labelData->where('branch_fantasy_name', 'Sucursal Norte');

        $this->assertCount(3, $branchALabels, 'Branch A should have 3 label groups (MZC, JUG, ARR)');
        $this->assertCount(3, $branchBLabels, 'Branch B should have 3 label groups (MZC, JUG, ARR)');
        $this->assertCount(3, $branchCLabels, 'Branch C should have 3 label groups (MZC, JUG, ARR)');

        // Verify ingredient quantities for Branch A (2 dishes)
        $branchA_MZC = $branchALabels->firstWhere('ingredient_name', 'MZC - CONSOME DE POLLO GRANEL');
        $this->assertNotNull($branchA_MZC);
        $this->assertEquals(200, $branchA_MZC['total_quantity_needed'], 'Branch A MZC: 2 dishes × 100 GR = 200 GR');

        $branchA_JUG = $branchALabels->firstWhere('ingredient_name', 'JUG - JUGO DE NARANJA');
        $this->assertNotNull($branchA_JUG);
        $this->assertEquals(100, $branchA_JUG['total_quantity_needed'], 'Branch A JUG: 2 dishes × 50 ML = 100 ML');

        $branchA_ARR = $branchALabels->firstWhere('ingredient_name', 'ARR - ARROZ BLANCO');
        $this->assertNotNull($branchA_ARR);
        $this->assertEquals(300, $branchA_ARR['total_quantity_needed'], 'Branch A ARR: 2 dishes × 150 GR = 300 GR');

        // Verify ingredient quantities for Branch B (3 dishes)
        $branchB_MZC = $branchBLabels->firstWhere('ingredient_name', 'MZC - CONSOME DE POLLO GRANEL');
        $this->assertNotNull($branchB_MZC);
        $this->assertEquals(300, $branchB_MZC['total_quantity_needed'], 'Branch B MZC: 3 dishes × 100 GR = 300 GR');

        $branchB_JUG = $branchBLabels->firstWhere('ingredient_name', 'JUG - JUGO DE NARANJA');
        $this->assertNotNull($branchB_JUG);
        $this->assertEquals(150, $branchB_JUG['total_quantity_needed'], 'Branch B JUG: 3 dishes × 50 ML = 150 ML');

        $branchB_ARR = $branchBLabels->firstWhere('ingredient_name', 'ARR - ARROZ BLANCO');
        $this->assertNotNull($branchB_ARR);
        $this->assertEquals(450, $branchB_ARR['total_quantity_needed'], 'Branch B ARR: 3 dishes × 150 GR = 450 GR');

        // Verify ingredient quantities for Branch C (1 dish)
        $branchC_MZC = $branchCLabels->firstWhere('ingredient_name', 'MZC - CONSOME DE POLLO GRANEL');
        $this->assertNotNull($branchC_MZC);
        $this->assertEquals(100, $branchC_MZC['total_quantity_needed'], 'Branch C MZC: 1 dish × 100 GR = 100 GR');

        $branchC_JUG = $branchCLabels->firstWhere('ingredient_name', 'JUG - JUGO DE NARANJA');
        $this->assertNotNull($branchC_JUG);
        $this->assertEquals(50, $branchC_JUG['total_quantity_needed'], 'Branch C JUG: 1 dish × 50 ML = 50 ML');

        $branchC_ARR = $branchCLabels->firstWhere('ingredient_name', 'ARR - ARROZ BLANCO');
        $this->assertNotNull($branchC_ARR);
        $this->assertEquals(150, $branchC_ARR['total_quantity_needed'], 'Branch C ARR: 1 dish × 150 GR = 150 GR');
    }

    /**
     * Test complex scenario with multiple products exceeding max_quantity_horeca
     *
     * SETUP:
     * - Product 1: HORECA BOWL COMPLETO with 3 ingredients (MZC, JUG, ARR)
     *   - MZC: 300 GR per dish, max: 500 GR (low limit to force splits)
     *   - JUG: 200 ML per dish, max: 350 ML (low limit)
     *   - ARR: 150 GR per dish, max: 1000 GR (high limit)
     *
     * - Product 2: HORECA ENSALADA PREMIUM with 2 ingredients (ENS, ADE)
     *   - ENS: 250 GR per dish, max: 400 GR (low limit)
     *   - ADE: 50 ML per dish, max: 200 ML
     *
     * ORDERS:
     * - Branch A (Sucursal Centro): 5 bowls + 3 salads
     * - Branch B (Sucursal Sur): 2 bowls + 6 salads
     * - Branch C (Sucursal Norte): 3 bowls
     *
     * EXPECTED LABEL GROUPS: 13 total (branch-ingredient combinations)
     * - Sucursal Centro: 5 groups (MZC, JUG, ARR, ENS, ADE)
     * - Sucursal Sur: 5 groups (MZC, JUG, ARR, ENS, ADE)
     * - Sucursal Norte: 3 groups (MZC, JUG, ARR)
     *
     * EXPECTED PHYSICAL LABELS: 26 total (sum of all labels_count)
     * - Sucursal Centro: 10 labels (3+3+1+2+1)
     * - Sucursal Sur: 11 labels (2+2+1+4+2)
     * - Sucursal Norte: 5 labels (2+2+1)
     *
     * This test validates:
     * - Correct splitting when quantities exceed max_quantity_horeca
     * - Multiple products per order
     * - Branch grouping and ordering
     * - Accurate weight calculations in splits
     */
    public function test_complex_scenario_with_multiple_products_exceeding_max_quantity(): void
    {
        // ===== Create Product 1: HORECA BOWL COMPLETO =====
        $productBowl = Product::create([
            'name' => 'HORECA BOWL COMPLETO',
            'code' => 'ACM-BOWL-001',
            'description' => 'Complete bowl with multiple ingredients',
            'category_id' => $this->category->id,
            'price' => 8000,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $platedDishBowl = PlatedDish::create([
            'product_id' => $productBowl->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // Bowl ingredients (low max limits to force splits)
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishBowl->id,
            'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 300,
            'max_quantity_horeca' => 500, // Low limit
            'order_index' => 0,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishBowl->id,
            'ingredient_name' => 'JUG - JUGO DE NARANJA',
            'measure_unit' => 'ML',
            'quantity' => 200,
            'max_quantity_horeca' => 350, // Low limit
            'order_index' => 1,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishBowl->id,
            'ingredient_name' => 'ARR - ARROZ BLANCO',
            'measure_unit' => 'GR',
            'quantity' => 150,
            'max_quantity_horeca' => 1000, // High limit
            'order_index' => 2,
        ]);

        // ===== Create Product 2: HORECA ENSALADA PREMIUM =====
        $productSalad = Product::create([
            'name' => 'HORECA ENSALADA PREMIUM',
            'code' => 'ACM-ENS-001',
            'description' => 'Premium salad with dressing',
            'category_id' => $this->category->id,
            'price' => 6000,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $platedDishSalad = PlatedDish::create([
            'product_id' => $productSalad->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // Salad ingredients
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishSalad->id,
            'ingredient_name' => 'ENS - ENSALADA MIXTA',
            'measure_unit' => 'GR',
            'quantity' => 250,
            'max_quantity_horeca' => 400, // Low limit
            'order_index' => 0,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishSalad->id,
            'ingredient_name' => 'ADE - ADEREZO CASERO',
            'measure_unit' => 'ML',
            'quantity' => 50,
            'max_quantity_horeca' => 200,
            'order_index' => 1,
        ]);

        // ===== Create additional companies and branches =====
        $companyB = Company::create([
            'name' => 'TEST COMPANY B S.A.',
            'email' => 'company.b@test.com',
            'active' => true,
            'fantasy_name' => 'TEST COMPANY B',
        ]);

        $branchB = Branch::create([
            'company_id' => $companyB->id,
            'fantasy_name' => 'Sucursal Sur',
            'address' => 'Address B',
            'min_price_order' => 0,
        ]);

        $companyC = Company::create([
            'name' => 'TEST COMPANY C S.A.',
            'email' => 'company.c@test.com',
            'active' => true,
            'fantasy_name' => 'TEST COMPANY C',
        ]);

        $branchC = Branch::create([
            'company_id' => $companyC->id,
            'fantasy_name' => 'Sucursal Norte',
            'address' => 'Address C',
            'min_price_order' => 0,
        ]);

        // Create users for each branch
        $userBranchB = User::create([
            'name' => 'User Branch B',
            'email' => 'user.branch.b.complex@test.com',
            'password' => bcrypt('password'),
            'branch_id' => $branchB->id,
            'company_id' => $companyB->id,
            'nickname' => 'user.branch.b.complex',
        ]);

        $userBranchC = User::create([
            'name' => 'User Branch C',
            'email' => 'user.branch.c.complex@test.com',
            'password' => bcrypt('password'),
            'branch_id' => $branchC->id,
            'company_id' => $companyC->id,
            'nickname' => 'user.branch.c.complex',
        ]);

        // ===== CREATE ORDERS =====

        // Branch A: 5 bowls + 3 salads
        $orderA = Order::create([
            'user_id' => $this->userBranchA->id,
            'total' => 58000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderA->id,
            'product_id' => $productBowl->id,
            'quantity' => 5,
            'unit_price' => 8000,
        ]);

        OrderLine::create([
            'order_id' => $orderA->id,
            'product_id' => $productSalad->id,
            'quantity' => 3,
            'unit_price' => 6000,
        ]);

        // Branch B: 2 bowls + 6 salads
        $orderB = Order::create([
            'user_id' => $userBranchB->id,
            'total' => 52000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderB->id,
            'product_id' => $productBowl->id,
            'quantity' => 2,
            'unit_price' => 8000,
        ]);

        OrderLine::create([
            'order_id' => $orderB->id,
            'product_id' => $productSalad->id,
            'quantity' => 6,
            'unit_price' => 6000,
        ]);

        // Branch C: 3 bowls only
        $orderC = Order::create([
            'user_id' => $userBranchC->id,
            'total' => 24000,
            'status' => 'pending',
        ]);

        OrderLine::create([
            'order_id' => $orderC->id,
            'product_id' => $productBowl->id,
            'quantity' => 3,
            'unit_price' => 8000,
        ]);

        // ===== EXECUTE REPOSITORY METHOD =====
        $labelData = $this->repository->getHorecaLabelDataByOrders([
            $orderA->id,
            $orderB->id,
            $orderC->id,
        ]);

        // ===== ASSERTIONS =====

        // 1. TOTAL COUNT: Should have 13 ingredient groups (branch-ingredient combinations)
        $this->assertCount(13, $labelData, 'Should have 13 total label groups (5+5+3 ingredient groups)');

        // 2. BRANCH GROUPING: Verify consecutive branch grouping
        $branchIdsInOrder = $labelData->pluck('branch_id')->toArray();

        $branchGroups = [];
        $currentBranch = null;
        $currentGroup = [];

        foreach ($branchIdsInOrder as $branchId) {
            if ($branchId !== $currentBranch) {
                if (!empty($currentGroup)) {
                    $branchGroups[] = $currentGroup;
                }
                $currentBranch = $branchId;
                $currentGroup = [$branchId];
            } else {
                $currentGroup[] = $branchId;
            }
        }

        if (!empty($currentGroup)) {
            $branchGroups[] = $currentGroup;
        }

        $this->assertCount(3, $branchGroups, 'Should have 3 branch groups (A, B, C)');

        // 3. VERIFY COUNTS PER BRANCH (ingredient groups, NOT physical labels)
        $branchALabels = $labelData->where('branch_fantasy_name', 'Sucursal Centro');
        $branchBLabels = $labelData->where('branch_fantasy_name', 'Sucursal Sur');
        $branchCLabels = $labelData->where('branch_fantasy_name', 'Sucursal Norte');

        $this->assertCount(5, $branchALabels, 'Branch A should have 5 ingredient groups (MZC, JUG, ARR, ENS, ADE)');
        $this->assertCount(5, $branchBLabels, 'Branch B should have 5 ingredient groups (MZC, JUG, ARR, ENS, ADE)');
        $this->assertCount(3, $branchCLabels, 'Branch C should have 3 ingredient groups (MZC, JUG, ARR)');

        // 4. VERIFY TOTAL PHYSICAL LABELS (sum of all labels_count fields)
        $totalPhysicalLabels = $labelData->sum('labels_count');
        $this->assertEquals(26, $totalPhysicalLabels, 'Should have 26 physical labels total (10+11+5)');

        // 5. VERIFY BRANCH A LABELS (5 bowls + 3 salads = 5 ingredient groups)

        // MZC: 5 × 300 = 1500 GR → max 500 → labels_count: 3, weights: [500, 500, 500]
        $branchA_MZC = $branchALabels->firstWhere('ingredient_name', 'MZC - CONSOME DE POLLO GRANEL');
        $this->assertNotNull($branchA_MZC);
        $this->assertEquals(1500, $branchA_MZC['total_quantity_needed'], 'Branch A MZC total: 5 × 300 = 1500 GR');
        $this->assertEquals(3, $branchA_MZC['labels_count']);
        $this->assertEquals([500, 500, 500], $branchA_MZC['weights']);

        // JUG: 5 × 200 = 1000 ML → max 350 → labels_count: 3, weights: [350, 350, 300]
        $branchA_JUG = $branchALabels->firstWhere('ingredient_name', 'JUG - JUGO DE NARANJA');
        $this->assertNotNull($branchA_JUG);
        $this->assertEquals(1000, $branchA_JUG['total_quantity_needed'], 'Branch A JUG total: 5 × 200 = 1000 ML');
        $this->assertEquals(3, $branchA_JUG['labels_count']);
        $this->assertEquals([350, 350, 300], $branchA_JUG['weights']);

        // ARR: 5 × 150 = 750 GR → max 1000 → labels_count: 1, weights: [750]
        $branchA_ARR = $branchALabels->firstWhere('ingredient_name', 'ARR - ARROZ BLANCO');
        $this->assertNotNull($branchA_ARR);
        $this->assertEquals(750, $branchA_ARR['total_quantity_needed'], 'Branch A ARR total: 5 × 150 = 750 GR');
        $this->assertEquals(1, $branchA_ARR['labels_count']);
        $this->assertEquals([750], $branchA_ARR['weights']);

        // ENS: 3 × 250 = 750 GR → max 400 → labels_count: 2, weights: [400, 350]
        $branchA_ENS = $branchALabels->firstWhere('ingredient_name', 'ENS - ENSALADA MIXTA');
        $this->assertNotNull($branchA_ENS);
        $this->assertEquals(750, $branchA_ENS['total_quantity_needed'], 'Branch A ENS total: 3 × 250 = 750 GR');
        $this->assertEquals(2, $branchA_ENS['labels_count']);
        $this->assertEquals([400, 350], $branchA_ENS['weights']);

        // ADE: 3 × 50 = 150 ML → max 200 → labels_count: 1, weights: [150]
        $branchA_ADE = $branchALabels->firstWhere('ingredient_name', 'ADE - ADEREZO CASERO');
        $this->assertNotNull($branchA_ADE);
        $this->assertEquals(150, $branchA_ADE['total_quantity_needed'], 'Branch A ADE total: 3 × 50 = 150 ML');
        $this->assertEquals(1, $branchA_ADE['labels_count']);
        $this->assertEquals([150], $branchA_ADE['weights']);

        // 6. VERIFY BRANCH B LABELS (2 bowls + 6 salads = 5 ingredient groups)

        // MZC: 2 × 300 = 600 GR → max 500 → labels_count: 2, weights: [500, 100]
        $branchB_MZC = $branchBLabels->firstWhere('ingredient_name', 'MZC - CONSOME DE POLLO GRANEL');
        $this->assertNotNull($branchB_MZC);
        $this->assertEquals(600, $branchB_MZC['total_quantity_needed'], 'Branch B MZC total: 2 × 300 = 600 GR');
        $this->assertEquals(2, $branchB_MZC['labels_count']);
        $this->assertEquals([500, 100], $branchB_MZC['weights']);

        // JUG: 2 × 200 = 400 ML → max 350 → labels_count: 2, weights: [350, 50]
        $branchB_JUG = $branchBLabels->firstWhere('ingredient_name', 'JUG - JUGO DE NARANJA');
        $this->assertNotNull($branchB_JUG);
        $this->assertEquals(400, $branchB_JUG['total_quantity_needed'], 'Branch B JUG total: 2 × 200 = 400 ML');
        $this->assertEquals(2, $branchB_JUG['labels_count']);
        $this->assertEquals([350, 50], $branchB_JUG['weights']);

        // ARR: 2 × 150 = 300 GR → max 1000 → labels_count: 1, weights: [300]
        $branchB_ARR = $branchBLabels->firstWhere('ingredient_name', 'ARR - ARROZ BLANCO');
        $this->assertNotNull($branchB_ARR);
        $this->assertEquals(300, $branchB_ARR['total_quantity_needed'], 'Branch B ARR total: 2 × 150 = 300 GR');
        $this->assertEquals(1, $branchB_ARR['labels_count']);
        $this->assertEquals([300], $branchB_ARR['weights']);

        // ENS: 6 × 250 = 1500 GR → max 400 → labels_count: 4, weights: [400, 400, 400, 300]
        $branchB_ENS = $branchBLabels->firstWhere('ingredient_name', 'ENS - ENSALADA MIXTA');
        $this->assertNotNull($branchB_ENS);
        $this->assertEquals(1500, $branchB_ENS['total_quantity_needed'], 'Branch B ENS total: 6 × 250 = 1500 GR');
        $this->assertEquals(4, $branchB_ENS['labels_count']);
        $this->assertEquals([400, 400, 400, 300], $branchB_ENS['weights']);

        // ADE: 6 × 50 = 300 ML → max 200 → labels_count: 2, weights: [200, 100]
        $branchB_ADE = $branchBLabels->firstWhere('ingredient_name', 'ADE - ADEREZO CASERO');
        $this->assertNotNull($branchB_ADE);
        $this->assertEquals(300, $branchB_ADE['total_quantity_needed'], 'Branch B ADE total: 6 × 50 = 300 ML');
        $this->assertEquals(2, $branchB_ADE['labels_count']);
        $this->assertEquals([200, 100], $branchB_ADE['weights']);

        // 7. VERIFY BRANCH C LABELS (3 bowls only = 3 ingredient groups)

        // MZC: 3 × 300 = 900 GR → max 500 → labels_count: 2, weights: [500, 400]
        $branchC_MZC = $branchCLabels->firstWhere('ingredient_name', 'MZC - CONSOME DE POLLO GRANEL');
        $this->assertNotNull($branchC_MZC);
        $this->assertEquals(900, $branchC_MZC['total_quantity_needed'], 'Branch C MZC total: 3 × 300 = 900 GR');
        $this->assertEquals(2, $branchC_MZC['labels_count']);
        $this->assertEquals([500, 400], $branchC_MZC['weights']);

        // JUG: 3 × 200 = 600 ML → max 350 → labels_count: 2, weights: [350, 250]
        $branchC_JUG = $branchCLabels->firstWhere('ingredient_name', 'JUG - JUGO DE NARANJA');
        $this->assertNotNull($branchC_JUG);
        $this->assertEquals(600, $branchC_JUG['total_quantity_needed'], 'Branch C JUG total: 3 × 200 = 600 ML');
        $this->assertEquals(2, $branchC_JUG['labels_count']);
        $this->assertEquals([350, 250], $branchC_JUG['weights']);

        // ARR: 3 × 150 = 450 GR → max 1000 → labels_count: 1, weights: [450]
        $branchC_ARR = $branchCLabels->firstWhere('ingredient_name', 'ARR - ARROZ BLANCO');
        $this->assertNotNull($branchC_ARR);
        $this->assertEquals(450, $branchC_ARR['total_quantity_needed'], 'Branch C ARR total: 3 × 150 = 450 GR');
        $this->assertEquals(1, $branchC_ARR['labels_count']);
        $this->assertEquals([450], $branchC_ARR['weights']);

        // 8. VERIFY COLLECTION ORDER (grouped by branch)
        // First 5 should be Branch A, next 5 Branch B, last 3 Branch C
        $first5 = $labelData->take(5);
        $next5 = $labelData->slice(5, 5);
        $last3 = $labelData->slice(10, 3);

        $this->assertTrue(
            $first5->every(fn($item) => $item['branch_fantasy_name'] === 'Sucursal Centro'),
            'First 5 groups should all be from Sucursal Centro'
        );

        $this->assertTrue(
            $next5->every(fn($item) => $item['branch_fantasy_name'] === 'Sucursal Sur'),
            'Next 5 groups should all be from Sucursal Sur'
        );

        $this->assertTrue(
            $last3->every(fn($item) => $item['branch_fantasy_name'] === 'Sucursal Norte'),
            'Last 3 groups should all be from Sucursal Norte'
        );
    }
}