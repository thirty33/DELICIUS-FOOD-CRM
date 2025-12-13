<?php

namespace Tests\Feature\Reports;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderStatus;
use App\Exports\ConsolidadoEmplatadoDataExport;
use App\Models\AdvanceOrder;
use App\Repositories\OrderRepository;
use App\Repositories\ConsolidadoEmplatadoRepository;
use App\Support\ImportExport\ConsolidadoEmplatadoSchema;
use App\Models\AdvanceOrderOrder;
use App\Models\AdvanceOrderOrderLine;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PlatedDish;
use App\Models\PlatedDishIngredient;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\ProductionArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Helpers\ReportGrouperTestHelper;
use Tests\TestCase;

/**
 * Test for Consolidado Emplatado Report - ONE COMPLETE ROW
 *
 * STRUCTURE:
 * - Product: HORECA TOMATICAN DE VACUNO CON ARROZ CASERO (with PlatedDish)
 * - 2 Ingredients:
 *   1. MZC - TOMATICAN DE VACUNO GRANEL (200g/PAX, max 1000g)
 *   2. MZC - ARROZ CASERO (220g/PAX, max 1000g)
 * - 4 Companies/Branches: OTERO (6), ALIACE (19), UNICON LE (44), UNICON PA (26)
 * - TOTAL HORECA: 95 portions
 *
 * EXPECTED CALCULATIONS:
 * Ingredient 1 (Tomaticán):
 * - OTERO: 6 × 200 = 1200g → [1000, 200]
 * - ALIACE: 19 × 200 = 3800g → [1000, 1000, 1000, 800]
 * - UNICON LE: 44 × 200 = 8800g → [1000×8, 800]
 * - UNICON PA: 26 × 200 = 5200g → [1000×5, 200]
 * - TOTAL BOLSAS: 17×1000g, 2×800g, 2×200g
 *
 * Ingredient 2 (Arroz):
 * - OTERO: 6 × 220 = 1320g → [1000, 320]
 * - ALIACE: 19 × 220 = 4180g → [1000×4, 180]
 * - UNICON LE: 44 × 220 = 9680g → [1000×9, 680]
 * - UNICON PA: 26 × 220 = 5720g → [1000×5, 720]
 * - TOTAL BOLSAS: 19×1000g, 1×720g, 1×680g, 1×320g, 1×180g
 *
 * GROUPERS:
 * - This test creates ReportGroupers that emulate the branch columns
 * - Each grouper has the same name as the branch fantasy_name
 * - This prepares for migration from branch-based to grouper-based columns
 */
class ConsolidadoEmplatadoReportTest extends TestCase
{
    use RefreshDatabase;
    use ReportGrouperTestHelper;

    public function test_consolidado_emplatado_query_returns_correct_structure_for_one_plated_dish(): void
    {
        // 1. CREATE PRODUCTION AREA
        $productionArea = ProductionArea::create([
            'name' => 'Cocina HORECA Test',
            'description' => 'Área de producción HORECA para tests',
        ]);

        // 2. CREATE CATEGORY
        $category = Category::create([
            'name' => 'Platos de Fondo',
            'code' => 'PCH',
            'description' => 'Platos Caseros HORECA',
            'active' => true,
        ]);

        // 3. CREATE PRODUCT (Plato HORECA Final)
        $product = Product::create([
            'name' => 'HORECA TOMATICAN DE VACUNO CON ARROZ CASERO',
            'code' => 'PCH-TOMATICAN',
            'description' => 'Plato HORECA Tomatican con Arroz Casero',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        // Attach product to production area
        $product->productionAreas()->attach($productionArea->id);

        // 4. CREATE PLATED DISH
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // 5. CREATE INGREDIENTS (2 ingredients)
        $ingredient1 = PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - TOMATICAN DE VACUNO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 200,
            'max_quantity_horeca' => 1000,
            'order_index' => 1,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        $ingredient2 = PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - ARROZ CASERO',
            'measure_unit' => 'GR',
            'quantity' => 220,
            'max_quantity_horeca' => 1000,
            'order_index' => 2,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // 6. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'Lista HORECA Test',
            'description' => 'Precios HORECA para tests',
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 5000,
        ]);

        // 7. CREATE COMPANIES AND BRANCHES (4 clients)

        // Client 1: OTERO - 6 portions
        $companyOtero = Company::create([
            'name' => 'OTERO HORECA S.A.',
            'email' => 'otero@horeca-test.com',
            'tax_id' => '11111111-1',
            'company_code' => 'OTERO',
            'fantasy_name' => 'Otero',
            'price_list_id' => $priceList->id,
        ]);
        $branchOtero = Branch::create([
            'company_id' => $companyOtero->id,
            'fantasy_name' => 'OTERO HORECA',
            'branch_code' => 'OTERO-01',
            'address' => 'Dirección Otero Test',
            'min_price_order' => 0,
        ]);

        // Client 2: ALIACE - 19 portions
        $companyAliace = Company::create([
            'name' => 'ALIACE HORECA S.A.',
            'email' => 'aliace@horeca-test.com',
            'tax_id' => '22222222-2',
            'company_code' => 'ALIACE',
            'fantasy_name' => 'Aliace',
            'price_list_id' => $priceList->id,
        ]);
        $branchAliace = Branch::create([
            'company_id' => $companyAliace->id,
            'fantasy_name' => 'ALIACE HORECA',
            'branch_code' => 'ALIACE-01',
            'address' => 'Dirección Aliace Test',
            'min_price_order' => 0,
        ]);

        // Client 3: UNICON LO ESPEJO - 44 portions
        $companyUniconLE = Company::create([
            'name' => 'UNICON LO ESPEJO S.A.',
            'email' => 'uniconle@horeca-test.com',
            'tax_id' => '33333333-3',
            'company_code' => 'UNICONLE',
            'fantasy_name' => 'Unicon Lo Espejo',
            'price_list_id' => $priceList->id,
        ]);
        $branchUniconLE = Branch::create([
            'company_id' => $companyUniconLE->id,
            'fantasy_name' => 'UNICON LO ESPEJO',
            'branch_code' => 'UNICON-LE-01',
            'address' => 'Dirección Unicon LE Test',
            'min_price_order' => 0,
        ]);

        // Client 4: UNICON PANAMERICA - 26 portions
        $companyUniconPA = Company::create([
            'name' => 'UNICON PANAMERICA S.A.',
            'email' => 'uniconpa@horeca-test.com',
            'tax_id' => '44444444-4',
            'company_code' => 'UNICONPA',
            'fantasy_name' => 'Unicon Panamericana',
            'price_list_id' => $priceList->id,
        ]);
        $branchUniconPA = Branch::create([
            'company_id' => $companyUniconPA->id,
            'fantasy_name' => 'UNICON PANAMERICA',
            'branch_code' => 'UNICON-PA-01',
            'address' => 'Dirección Unicon PA Test',
            'min_price_order' => 0,
        ]);

        // 7.1 CREATE REPORT GROUPERS
        // Create groupers with same names as branch fantasy_names for column matching
        // This prepares for migration from branch-based to grouper-based columns
        $this->createGroupersByName([
            ['name' => 'ALIACE HORECA', 'company_id' => $companyAliace->id],
            ['name' => 'OTERO HORECA', 'company_id' => $companyOtero->id],
            ['name' => 'UNICON LO ESPEJO', 'company_id' => $companyUniconLE->id],
            ['name' => 'UNICON PANAMERICA', 'company_id' => $companyUniconPA->id],
        ]);

        // Verify groupers were created
        $this->assertCount(4, $this->getCreatedGroupers());

        // 8. CREATE USERS (one per client)
        $userOtero = User::create([
            'name' => 'Usuario Otero Test',
            'nickname' => 'OTERO.USER',
            'email' => 'otero@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyOtero->id,
            'branch_id' => $branchOtero->id,
        ]);

        $userAliace = User::create([
            'name' => 'Usuario Aliace Test',
            'nickname' => 'ALIACE.USER',
            'email' => 'aliace@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyAliace->id,
            'branch_id' => $branchAliace->id,
        ]);

        $userUniconLE = User::create([
            'name' => 'Usuario Unicon LE Test',
            'nickname' => 'UNICONLE.USER',
            'email' => 'uniconle@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyUniconLE->id,
            'branch_id' => $branchUniconLE->id,
        ]);

        $userUniconPA = User::create([
            'name' => 'Usuario Unicon PA Test',
            'nickname' => 'UNICONPA.USER',
            'email' => 'uniconpa@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyUniconPA->id,
            'branch_id' => $branchUniconPA->id,
        ]);

        // 9. CREATE ORDERS (4 orders, one per client)
        $orderOtero = Order::create([
            'user_id' => $userOtero->id,
            'branch_id' => $branchOtero->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-01-15',
            'date' => '2025-01-15',
            'order_number' => 'ORD-OTERO-001',
            'total' => 30000,
            'total_with_tax' => 35700,
            'tax_amount' => 5700,
            'grand_total' => 35700,
            'dispatch_cost' => 0,
        ]);

        $orderAliace = Order::create([
            'user_id' => $userAliace->id,
            'branch_id' => $branchAliace->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-01-15',
            'date' => '2025-01-15',
            'order_number' => 'ORD-ALIACE-001',
            'total' => 95000,
            'total_with_tax' => 113050,
            'tax_amount' => 18050,
            'grand_total' => 113050,
            'dispatch_cost' => 0,
        ]);

        $orderUniconLE = Order::create([
            'user_id' => $userUniconLE->id,
            'branch_id' => $branchUniconLE->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-01-15',
            'date' => '2025-01-15',
            'order_number' => 'ORD-UNICONLE-001',
            'total' => 220000,
            'total_with_tax' => 261800,
            'tax_amount' => 41800,
            'grand_total' => 261800,
            'dispatch_cost' => 0,
        ]);

        $orderUniconPA = Order::create([
            'user_id' => $userUniconPA->id,
            'branch_id' => $branchUniconPA->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-01-15',
            'date' => '2025-01-15',
            'order_number' => 'ORD-UNICONPA-001',
            'total' => 130000,
            'total_with_tax' => 154700,
            'tax_amount' => 24700,
            'grand_total' => 154700,
            'dispatch_cost' => 0,
        ]);

        // 10. CREATE ORDER LINES (4 lines with different quantities)

        // OTERO: 6 portions
        $orderLineOtero = OrderLine::create([
            'order_id' => $orderOtero->id,
            'product_id' => $product->id,
            'quantity' => 6,
            'unit_price' => 5000,
            'subtotal' => 30000,
        ]);

        // ALIACE: 19 portions
        $orderLineAliace = OrderLine::create([
            'order_id' => $orderAliace->id,
            'product_id' => $product->id,
            'quantity' => 19,
            'unit_price' => 5000,
            'subtotal' => 95000,
        ]);

        // UNICON LO ESPEJO: 44 portions
        $orderLineUniconLE = OrderLine::create([
            'order_id' => $orderUniconLE->id,
            'product_id' => $product->id,
            'quantity' => 44,
            'unit_price' => 5000,
            'subtotal' => 220000,
        ]);

        // UNICON PANAMERICA: 26 portions
        $orderLineUniconPA = OrderLine::create([
            'order_id' => $orderUniconPA->id,
            'product_id' => $product->id,
            'quantity' => 26,
            'unit_price' => 5000,
            'subtotal' => 130000,
        ]);

        // 11. CREATE ADVANCE ORDER using OrderRepository
        // This automatically creates the pivots (AdvanceOrderOrder and AdvanceOrderOrderLine)
        $repository = new OrderRepository();
        $advanceOrder = $repository->createAdvanceOrderFromOrders(
            [$orderOtero->id, $orderAliace->id, $orderUniconLE->id, $orderUniconPA->id],
            '2025-01-15 08:00:00',
            [$productionArea->id] // Filter by production area
        );

        // 12. QUERY TO GET CONSOLIDADO DATA
        // This is the query that the repository will implement
        $result = AdvanceOrder::where('id', $advanceOrder->id)
            ->with([
                'associatedOrderLines.orderLine.product.platedDish.ingredients' => function ($query) {
                    $query->where('is_optional', false)->orderBy('order_index');
                },
                'associatedOrderLines.order.user.branch',
            ])
            ->get();

        // 13. ASSERTIONS - Verify data structure is correct
        $this->assertCount(1, $result);

        $advanceOrderLoaded = $result->first();
        $this->assertNotNull($advanceOrderLoaded);

        // Verify we have 4 order lines
        $orderLines = $advanceOrderLoaded->associatedOrderLines;
        $this->assertCount(4, $orderLines);

        // Verify all order lines have the same product with PlatedDish
        foreach ($orderLines as $aoOrderLine) {
            $this->assertNotNull($aoOrderLine->orderLine);
            $this->assertNotNull($aoOrderLine->orderLine->product);
            $this->assertNotNull($aoOrderLine->orderLine->product->platedDish);
            $this->assertCount(2, $aoOrderLine->orderLine->product->platedDish->ingredients);
        }

        // Verify branch data is loaded (through order.user.branch)
        foreach ($orderLines as $aoOrderLine) {
            $this->assertNotNull($aoOrderLine->order);
            $this->assertNotNull($aoOrderLine->order->user);
            $this->assertNotNull($aoOrderLine->order->user->branch);
            $this->assertNotNull($aoOrderLine->order->user->branch->fantasy_name);
        }

        // Verify ingredient data
        $firstIngredients = $orderLines->first()->orderLine->product->platedDish->ingredients;

        $ingredient1Data = $firstIngredients->where('ingredient_name', 'MZC - TOMATICAN DE VACUNO GRANEL')->first();
        $this->assertNotNull($ingredient1Data);
        $this->assertEquals(200, $ingredient1Data->quantity);
        $this->assertEquals(1000, $ingredient1Data->max_quantity_horeca);
        $this->assertEquals('GR', $ingredient1Data->measure_unit);

        $ingredient2Data = $firstIngredients->where('ingredient_name', 'MZC - ARROZ CASERO')->first();
        $this->assertNotNull($ingredient2Data);
        $this->assertEquals(220, $ingredient2Data->quantity);
        $this->assertEquals(1000, $ingredient2Data->max_quantity_horeca);
        $this->assertEquals('GR', $ingredient2Data->measure_unit);

        // Verify quantities per branch
        $quantitiesByBranch = $orderLines->mapWithKeys(function ($aoOrderLine) {
            return [$aoOrderLine->order->user->branch->fantasy_name => $aoOrderLine->orderLine->quantity];
        });

        $this->assertEquals(6, $quantitiesByBranch['OTERO HORECA']);
        $this->assertEquals(19, $quantitiesByBranch['ALIACE HORECA']);
        $this->assertEquals(44, $quantitiesByBranch['UNICON LO ESPEJO']);
        $this->assertEquals(26, $quantitiesByBranch['UNICON PANAMERICA']);

        // Verify TOTAL HORECA (sum of all portions)
        $totalHoreca = $orderLines->sum(fn($aoOrderLine) => $aoOrderLine->orderLine->quantity);
        $this->assertEquals(95, $totalHoreca);

        // 14. TEST REPOSITORY - Validate complete bag calculation logic
        $consolidadoRepository = app(ConsolidadoEmplatadoRepository::class);
        $consolidatedData = $consolidadoRepository->getConsolidatedPlatedDishData([$advanceOrder->id]);

        // Verify structure
        $this->assertIsArray($consolidatedData);
        $this->assertCount(1, $consolidatedData); // One product

        $productData = $consolidatedData[0];
        $this->assertEquals($product->id, $productData['product_id']);
        $this->assertEquals('HORECA TOMATICAN DE VACUNO CON ARROZ CASERO', $productData['product_name']);
        $this->assertEquals('PCH-TOMATICAN', $productData['product_code']);

        // Verify ingredients (2 ingredients)
        $this->assertArrayHasKey('ingredients', $productData);
        $this->assertCount(2, $productData['ingredients']);

        // ====================================================================
        // INGREDIENT 1: TOMATICAN (200g/PAX, max 1000g)
        // ====================================================================
        $ingredient1 = collect($productData['ingredients'])->firstWhere('ingredient_name', 'MZC - TOMATICAN DE VACUNO GRANEL');
        $this->assertNotNull($ingredient1);
        $this->assertEquals('GR', $ingredient1['measure_unit']);
        $this->assertEquals(200, $ingredient1['quantity_per_pax']);
        $this->assertEquals(95, $ingredient1['total_horeca']); // Total portions
        $this->assertEquals(0, $ingredient1['individual']); // Always 0 for HORECA

        // Verify clientes (branches) for ingredient 1
        $this->assertArrayHasKey('clientes', $ingredient1);
        $this->assertCount(4, $ingredient1['clientes']);

        $clientes1 = collect($ingredient1['clientes'])->keyBy('column_name');

        // OTERO: 6 × 200 = 1200g → [1000, 200]
        $otero1 = $clientes1['OTERO HORECA'];
        $this->assertEquals(6, $otero1['porciones']);
        $this->assertEquals(1200, $otero1['gramos']);
        $this->assertEquals([1000, 200], $otero1['weights']);
        $this->assertIsArray($otero1['descripcion']);
        $this->assertEquals(['1 BOLSA DE 1000 GRAMOS', '1 BOLSA DE 200 GRAMOS'], $otero1['descripcion']);

        // ALIACE: 19 × 200 = 3800g → [1000, 1000, 1000, 800]
        $aliace1 = $clientes1['ALIACE HORECA'];
        $this->assertEquals(19, $aliace1['porciones']);
        $this->assertEquals(3800, $aliace1['gramos']);
        $this->assertEquals([1000, 1000, 1000, 800], $aliace1['weights']);
        $this->assertIsArray($aliace1['descripcion']);
        $this->assertEquals(['3 BOLSAS DE 1000 GRAMOS', '1 BOLSA DE 800 GRAMOS'], $aliace1['descripcion']);

        // UNICON LO ESPEJO: 44 × 200 = 8800g → [1000×8, 800]
        $uniconLE1 = $clientes1['UNICON LO ESPEJO'];
        $this->assertEquals(44, $uniconLE1['porciones']);
        $this->assertEquals(8800, $uniconLE1['gramos']);
        $this->assertEquals([1000, 1000, 1000, 1000, 1000, 1000, 1000, 1000, 800], $uniconLE1['weights']);
        $this->assertIsArray($uniconLE1['descripcion']);
        $this->assertEquals(['8 BOLSAS DE 1000 GRAMOS', '1 BOLSA DE 800 GRAMOS'], $uniconLE1['descripcion']);

        // UNICON PANAMERICA: 26 × 200 = 5200g → [1000×5, 200]
        $uniconPA1 = $clientes1['UNICON PANAMERICA'];
        $this->assertEquals(26, $uniconPA1['porciones']);
        $this->assertEquals(5200, $uniconPA1['gramos']);
        $this->assertEquals([1000, 1000, 1000, 1000, 1000, 200], $uniconPA1['weights']);
        $this->assertIsArray($uniconPA1['descripcion']);
        $this->assertEquals(['5 BOLSAS DE 1000 GRAMOS', '1 BOLSA DE 200 GRAMOS'], $uniconPA1['descripcion']);

        // Verify TOTAL BOLSAS for ingredient 1
        // Consolidado: 17×1000g, 2×800g, 2×200g
        $this->assertArrayHasKey('total_bolsas', $ingredient1);
        $this->assertIsArray($ingredient1['total_bolsas']);
        $this->assertEquals([
            '17 BOLSAS DE 1000 GRAMOS',
            '2 BOLSAS DE 800 GRAMOS',
            '2 BOLSAS DE 200 GRAMOS',
        ], $ingredient1['total_bolsas']);

        // ====================================================================
        // INGREDIENT 2: ARROZ (220g/PAX, max 1000g)
        // ====================================================================
        $ingredient2 = collect($productData['ingredients'])->firstWhere('ingredient_name', 'MZC - ARROZ CASERO');
        $this->assertNotNull($ingredient2);
        $this->assertEquals('GR', $ingredient2['measure_unit']);
        $this->assertEquals(220, $ingredient2['quantity_per_pax']);
        $this->assertEquals(95, $ingredient2['total_horeca']); // Total portions
        $this->assertEquals(0, $ingredient2['individual']); // Always 0 for HORECA

        // Verify clientes (branches) for ingredient 2
        $this->assertCount(4, $ingredient2['clientes']);
        $clientes2 = collect($ingredient2['clientes'])->keyBy('column_name');

        // OTERO: 6 × 220 = 1320g → [1000, 320]
        $otero2 = $clientes2['OTERO HORECA'];
        $this->assertEquals(6, $otero2['porciones']);
        $this->assertEquals(1320, $otero2['gramos']);
        $this->assertEquals([1000, 320], $otero2['weights']);
        $this->assertIsArray($otero2['descripcion']);
        $this->assertEquals(['1 BOLSA DE 1000 GRAMOS', '1 BOLSA DE 320 GRAMOS'], $otero2['descripcion']);

        // ALIACE: 19 × 220 = 4180g → [1000×4, 180]
        $aliace2 = $clientes2['ALIACE HORECA'];
        $this->assertEquals(19, $aliace2['porciones']);
        $this->assertEquals(4180, $aliace2['gramos']);
        $this->assertEquals([1000, 1000, 1000, 1000, 180], $aliace2['weights']);
        $this->assertIsArray($aliace2['descripcion']);
        $this->assertEquals(['4 BOLSAS DE 1000 GRAMOS', '1 BOLSA DE 180 GRAMOS'], $aliace2['descripcion']);

        // UNICON LO ESPEJO: 44 × 220 = 9680g → [1000×9, 680]
        $uniconLE2 = $clientes2['UNICON LO ESPEJO'];
        $this->assertEquals(44, $uniconLE2['porciones']);
        $this->assertEquals(9680, $uniconLE2['gramos']);
        $this->assertEquals([1000, 1000, 1000, 1000, 1000, 1000, 1000, 1000, 1000, 680], $uniconLE2['weights']);
        $this->assertIsArray($uniconLE2['descripcion']);
        $this->assertEquals(['9 BOLSAS DE 1000 GRAMOS', '1 BOLSA DE 680 GRAMOS'], $uniconLE2['descripcion']);

        // UNICON PANAMERICA: 26 × 220 = 5720g → [1000×5, 720]
        $uniconPA2 = $clientes2['UNICON PANAMERICA'];
        $this->assertEquals(26, $uniconPA2['porciones']);
        $this->assertEquals(5720, $uniconPA2['gramos']);
        $this->assertEquals([1000, 1000, 1000, 1000, 1000, 720], $uniconPA2['weights']);
        $this->assertIsArray($uniconPA2['descripcion']);
        $this->assertEquals(['5 BOLSAS DE 1000 GRAMOS', '1 BOLSA DE 720 GRAMOS'], $uniconPA2['descripcion']);

        // Verify TOTAL BOLSAS for ingredient 2
        // Consolidado: 19×1000g, 1×720g, 1×680g, 1×320g, 1×180g
        $this->assertIsArray($ingredient2['total_bolsas']);
        $this->assertEquals([
            '19 BOLSAS DE 1000 GRAMOS',
            '1 BOLSA DE 720 GRAMOS',
            '1 BOLSA DE 680 GRAMOS',
            '1 BOLSA DE 320 GRAMOS',
            '1 BOLSA DE 180 GRAMOS',
        ], $ingredient2['total_bolsas']);

        // ====================================================================
        // 15. TEST FLAT FORMAT - Validate Excel-ready format
        // ====================================================================

        // Reset schema before test (cleanup from previous calls)
        ConsolidadoEmplatadoSchema::resetClientColumns();

        // Configure schema with branch names BEFORE getting flat data
        // This is now required since Repository no longer configures schema automatically
        $branchNamesForSchema = $consolidadoRepository->getBranchNamesFromAdvanceOrders([$advanceOrder->id]);
        ConsolidadoEmplatadoSchema::setClientColumns($branchNamesForSchema);

        // Get flat format data
        $flatData = $consolidadoRepository->getConsolidatedPlatedDishData([$advanceOrder->id], true);

        // Verify flat data structure
        $this->assertIsArray($flatData);
        $this->assertCount(3, $flatData); // 2 ingredient rows + 1 totals row

        // Verify schema was configured with branches
        $configuredBranches = ConsolidadoEmplatadoSchema::getClientColumns();
        $this->assertCount(4, $configuredBranches);
        $this->assertArrayHasKey('client_aliace_horeca', $configuredBranches);
        $this->assertArrayHasKey('client_otero_horeca', $configuredBranches);
        $this->assertArrayHasKey('client_unicon_lo_espejo', $configuredBranches);
        $this->assertArrayHasKey('client_unicon_panamerica', $configuredBranches);

        // Verify schema headers
        $headers = ConsolidadoEmplatadoSchema::getHeaders();
        $this->assertArrayHasKey('plato', $headers);
        $this->assertArrayHasKey('ingrediente', $headers);
        $this->assertArrayHasKey('cantidad_x_pax', $headers);
        $this->assertArrayHasKey('individual', $headers);
        $this->assertArrayHasKey('client_aliace_horeca', $headers);
        $this->assertArrayHasKey('client_otero_horeca', $headers);
        $this->assertArrayHasKey('client_unicon_lo_espejo', $headers);
        $this->assertArrayHasKey('client_unicon_panamerica', $headers);
        $this->assertArrayHasKey('total_horeca', $headers);
        $this->assertArrayHasKey('total_bolsas', $headers);

        // ====================================================================
        // ROW 1: INGREDIENT 1 (Tomaticán)
        // ====================================================================
        $row1 = $flatData[0];

        // Fixed prefix columns
        $this->assertEquals('HORECA TOMATICAN DE VACUNO CON ARROZ CASERO', $row1['plato']);
        $this->assertEquals('MZC - TOMATICAN DE VACUNO GRANEL', $row1['ingrediente']);
        $this->assertEquals('200 GRAMOS', $row1['cantidad_x_pax']);
        $this->assertEquals('0', $row1['individual']);

        // Dynamic client columns (sorted alphabetically: ALIACE, OTERO, UNICON LE, UNICON PA)
        $this->assertEquals("3 BOLSAS DE 1000 GRAMOS\n1 BOLSA DE 800 GRAMOS", $row1['client_aliace_horeca']);
        $this->assertEquals("1 BOLSA DE 1000 GRAMOS\n1 BOLSA DE 200 GRAMOS", $row1['client_otero_horeca']);
        $this->assertEquals("8 BOLSAS DE 1000 GRAMOS\n1 BOLSA DE 800 GRAMOS", $row1['client_unicon_lo_espejo']);
        $this->assertEquals("5 BOLSAS DE 1000 GRAMOS\n1 BOLSA DE 200 GRAMOS", $row1['client_unicon_panamerica']);

        // Fixed suffix columns
        $this->assertEquals('95', $row1['total_horeca']);
        $this->assertEquals("17 BOLSAS DE 1000 GRAMOS\n2 BOLSAS DE 800 GRAMOS\n2 BOLSAS DE 200 GRAMOS", $row1['total_bolsas']);

        // ====================================================================
        // ROW 2: INGREDIENT 2 (Arroz) - plato should be empty
        // ====================================================================
        $row2 = $flatData[1];

        // Fixed prefix columns
        $this->assertEquals('', $row2['plato']); // Empty for second ingredient (merged)
        $this->assertEquals('MZC - ARROZ CASERO', $row2['ingrediente']);
        $this->assertEquals('220 GRAMOS', $row2['cantidad_x_pax']);
        $this->assertEquals('', $row2['individual']); // Empty for second ingredient (merged)

        // Dynamic client columns
        $this->assertEquals("4 BOLSAS DE 1000 GRAMOS\n1 BOLSA DE 180 GRAMOS", $row2['client_aliace_horeca']);
        $this->assertEquals("1 BOLSA DE 1000 GRAMOS\n1 BOLSA DE 320 GRAMOS", $row2['client_otero_horeca']);
        $this->assertEquals("9 BOLSAS DE 1000 GRAMOS\n1 BOLSA DE 680 GRAMOS", $row2['client_unicon_lo_espejo']);
        $this->assertEquals("5 BOLSAS DE 1000 GRAMOS\n1 BOLSA DE 720 GRAMOS", $row2['client_unicon_panamerica']);

        // Fixed suffix columns
        $this->assertEquals('', $row2['total_horeca']); // Empty for second ingredient (merged)
        $this->assertEquals("19 BOLSAS DE 1000 GRAMOS\n1 BOLSA DE 720 GRAMOS\n1 BOLSA DE 680 GRAMOS\n1 BOLSA DE 320 GRAMOS\n1 BOLSA DE 180 GRAMOS", $row2['total_bolsas']);

        // Verify all keys in rows match schema keys
        $schemaKeys = ConsolidadoEmplatadoSchema::getHeaderKeys();
        $this->assertEquals($schemaKeys, array_keys($row1));
        $this->assertEquals($schemaKeys, array_keys($row2));

        // ====================================================================
        // 16. TEST EXCEL EXPORT - Generate actual Excel file
        // ====================================================================

        // Reset schema again before export
        ConsolidadoEmplatadoSchema::resetClientColumns();

        // Extract branch names for export
        $branchNames = $consolidadoRepository->getBranchNamesFromAdvanceOrders([$advanceOrder->id]);

        // Create export instance with branch names
        $export = new ConsolidadoEmplatadoDataExport(collect([$advanceOrder->id]), $branchNames, 999);

        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate Excel file
        $fileName = 'test-exports/consolidado-emplatado-' . now()->format('Y-m-d-His') . '.xlsx';
        $filePath = storage_path('app/' . $fileName);

        // Store using Laravel Excel
        $export->store($fileName, 'local');

        // Verify file exists
        $this->assertFileExists($filePath, "Excel file should be created at {$filePath}");

        // Load Excel file using PhpSpreadsheet
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify title (row 1)
        $this->assertEquals('CONSOLIDADO DE INGREDIENTES - EMPLATADO', $sheet->getCellByColumnAndRow(1, 1)->getValue());

        // Verify date row (row 2)
        $dateValue = $sheet->getCellByColumnAndRow(1, 2)->getValue();
        $this->assertStringContainsString('FECHA:', $dateValue);

        // Verify headers (row 3)
        $expectedHeaders = array_values(ConsolidadoEmplatadoSchema::getHeaders());
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 3)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        $this->assertEquals($expectedHeaders, $actualHeaders, 'Excel headers should match schema headers');

        // Verify row count (1 title + 1 date + 1 header + 2 ingredient rows + 1 totals row)
        $lastRow = $sheet->getHighestRow();
        $this->assertEquals(6, $lastRow, 'Should have 1 title + 1 date + 1 header + 2 ingredient rows + 1 totals row');

        // Verify data row 4 (Ingredient 1: Tomaticán)
        $this->assertEquals('HORECA TOMATICAN DE VACUNO CON ARROZ CASERO', $sheet->getCellByColumnAndRow(1, 4)->getValue());
        $this->assertEquals('MZC - TOMATICAN DE VACUNO GRANEL', $sheet->getCellByColumnAndRow(2, 4)->getValue());
        $this->assertEquals('200 GRAMOS', $sheet->getCellByColumnAndRow(3, 4)->getValue());
        $this->assertEquals('0', $sheet->getCellByColumnAndRow(4, 4)->getValue());

        // Verify data row 5 (Ingredient 2: Arroz) - plato and individual should be empty (merged)
        $this->assertEquals('', $sheet->getCellByColumnAndRow(1, 5)->getValue());
        $this->assertEquals('MZC - ARROZ CASERO', $sheet->getCellByColumnAndRow(2, 5)->getValue());
        $this->assertEquals('220 GRAMOS', $sheet->getCellByColumnAndRow(3, 5)->getValue());
        $this->assertEquals('', $sheet->getCellByColumnAndRow(4, 5)->getValue()); // Empty for merge

        // Print absolute file path for user verification
        echo "\n\n";
        echo "====================================================================\n";
        echo "✅ EXCEL FILE GENERATED SUCCESSFULLY\n";
        echo "====================================================================\n";
        echo "Absolute Path: {$filePath}\n";
        echo "====================================================================\n";
        echo "\n\n";

        // DO NOT delete the file - user wants to review it manually
        // File will remain at: /home/joel/DELICIUS-FOOD-CRM/storage/app/test-exports/consolidado-emplatado-*.xlsx
    }

    /**
     * TDD RED PHASE: Test that order lines appearing in multiple AdvanceOrders are NOT counted multiple times.
     *
     * PRODUCTION BUG SCENARIO (discovered 2025-12-12):
     * ================================================
     * Product: ACM - HORECA CONSOME DE POLLO INDIVIDUAL (300g/PAX)
     *
     * When generating the Consolidado Emplatado report for AdvanceOrders [94, 96, 97, 98, 100, 101],
     * the quantities were showing DOUBLE the expected values:
     *
     * Expected (from OPs report):
     * - ALIACE HORECA: 20 portions × 300g = 6,000g
     * - OTERO HORECA: 39 portions × 300g = 11,700g
     * - TOTAL: 59 portions
     *
     * Actual (from Consolidado Emplatado):
     * - ALIACE HORECA: 40 portions × 300g = 12,000g (DOUBLED!)
     * - OTERO HORECA: 78 portions × 300g = 23,400g (DOUBLED!)
     * - TOTAL: 118 portions (DOUBLED!)
     *
     * ROOT CAUSE:
     * ===========
     * The same order_line_id appears in multiple AdvanceOrders:
     * - AO 94 contains 160 order lines
     * - AO 100 contains 173 order lines (ALL 160 from AO 94 + 13 new)
     *
     * When the report processes BOTH AO 94 and AO 100, each order line is counted TWICE.
     *
     * EXPECTED BEHAVIOR:
     * ==================
     * The repository should deduplicate by order_line_id, so each order line is only
     * counted ONCE regardless of how many AdvanceOrders it appears in.
     *
     * This test creates a scenario with:
     * - 2 companies/branches (ALIACE, OTERO) with corresponding groupers
     * - 2 orders (1 per company) with 1 order line each
     * - 2 AdvanceOrders where AO2 contains ALL order lines from AO1 (duplicates)
     *
     * When generating report for [AO1, AO2], quantities should NOT be doubled.
     */
    public function test_order_lines_in_multiple_advance_orders_are_not_counted_multiple_times(): void
    {
        // 1. CREATE PRODUCTION AREA
        $productionArea = ProductionArea::create([
            'name' => 'Cocina HORECA Dedup Test',
            'description' => 'Production area for deduplication test',
        ]);

        // 2. CREATE CATEGORY
        $category = Category::create([
            'name' => 'Sopas HORECA',
            'code' => 'SOP',
            'description' => 'Sopas para HORECA',
            'active' => true,
        ]);

        // 3. CREATE PRODUCT (simulating ACM - HORECA CONSOME DE POLLO INDIVIDUAL)
        $product = Product::create([
            'name' => 'TEST HORECA CONSOME DE POLLO INDIVIDUAL',
            'code' => 'TEST-CONSOME-IND',
            'description' => 'Test product simulating production scenario',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        $product->productionAreas()->attach($productionArea->id);

        // 4. CREATE PLATED DISH (300g/PAX, max 1000g - same as production)
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        $ingredient = PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - CONSOME DE POLLO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 300, // 300g per portion (same as production)
            'max_quantity_horeca' => 1000,
            'order_index' => 1,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // 5. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'Lista Dedup Test',
            'description' => 'Price list for deduplication test',
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 3000,
        ]);

        // 6. CREATE COMPANIES AND BRANCHES (simulating ALIACE and OTERO)

        // ALIACE: Will have 20 portions (same as production)
        $companyAliace = Company::create([
            'name' => 'TEST ALIACE S.A.',
            'email' => 'aliace@dedup-test.com',
            'tax_id' => '11111111-1',
            'company_code' => 'T-ALIACE',
            'fantasy_name' => 'Test Aliace',
            'price_list_id' => $priceList->id,
        ]);
        $branchAliace = Branch::create([
            'company_id' => $companyAliace->id,
            'fantasy_name' => 'ALIACE HORECA',
            'branch_code' => 'T-ALIACE-01',
            'address' => 'Test Address Aliace',
            'min_price_order' => 0,
        ]);

        // OTERO: Will have 39 portions (same as production)
        $companyOtero = Company::create([
            'name' => 'TEST OTERO S.A.',
            'email' => 'otero@dedup-test.com',
            'tax_id' => '22222222-2',
            'company_code' => 'T-OTERO',
            'fantasy_name' => 'Test Otero',
            'price_list_id' => $priceList->id,
        ]);
        $branchOtero = Branch::create([
            'company_id' => $companyOtero->id,
            'fantasy_name' => 'OTERO HORECA',
            'branch_code' => 'T-OTERO-01',
            'address' => 'Test Address Otero',
            'min_price_order' => 0,
        ]);

        // 7. CREATE REPORT GROUPERS (simulating production groupers)
        $this->createGroupersByName([
            ['name' => 'ALIACE HORECA', 'company_id' => $companyAliace->id],
            ['name' => 'OTERO HORECA', 'company_id' => $companyOtero->id],
        ]);

        // 8. CREATE USERS
        $userAliace = User::create([
            'name' => 'Usuario Aliace Dedup Test',
            'nickname' => 'ALIACE.DEDUP',
            'email' => 'aliace-dedup@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyAliace->id,
            'branch_id' => $branchAliace->id,
        ]);

        $userOtero = User::create([
            'name' => 'Usuario Otero Dedup Test',
            'nickname' => 'OTERO.DEDUP',
            'email' => 'otero-dedup@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyOtero->id,
            'branch_id' => $branchOtero->id,
        ]);

        // 9. CREATE ORDERS

        // ALIACE order: 20 portions
        $orderAliace = Order::create([
            'user_id' => $userAliace->id,
            'branch_id' => $branchAliace->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-05',
            'date' => '2025-12-05',
            'order_number' => 'ORD-ALIACE-DEDUP',
            'total' => 60000,
            'total_with_tax' => 71400,
            'tax_amount' => 11400,
            'grand_total' => 71400,
            'dispatch_cost' => 0,
        ]);

        // OTERO order: 39 portions
        $orderOtero = Order::create([
            'user_id' => $userOtero->id,
            'branch_id' => $branchOtero->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-05',
            'date' => '2025-12-05',
            'order_number' => 'ORD-OTERO-DEDUP',
            'total' => 117000,
            'total_with_tax' => 139230,
            'tax_amount' => 22230,
            'grand_total' => 139230,
            'dispatch_cost' => 0,
        ]);

        // 10. CREATE ORDER LINES

        // ALIACE: 20 portions (same as production)
        $orderLineAliace = OrderLine::create([
            'order_id' => $orderAliace->id,
            'product_id' => $product->id,
            'quantity' => 20,
            'unit_price' => 3000,
            'subtotal' => 60000,
        ]);

        // OTERO: 39 portions (same as production)
        $orderLineOtero = OrderLine::create([
            'order_id' => $orderOtero->id,
            'product_id' => $product->id,
            'quantity' => 39,
            'unit_price' => 3000,
            'subtotal' => 117000,
        ]);

        // 11. CREATE ADVANCE ORDERS (simulating the production scenario)
        // In production:
        // - AO 94 had 160 lines
        // - AO 100 had 173 lines (ALL 160 from AO 94 + 13 new)
        //
        // We simulate this by:
        // - AO1: Contains both order lines
        // - AO2: Contains the SAME order lines (duplicates)

        $repository = new OrderRepository();

        // Create first AdvanceOrder with both orders
        $advanceOrder1 = $repository->createAdvanceOrderFromOrders(
            [$orderAliace->id, $orderOtero->id],
            '2025-12-05 08:00:00',
            [$productionArea->id]
        );

        // Create second AdvanceOrder with the SAME orders
        // This simulates the production bug where AO 100 contains all lines from AO 94
        $advanceOrder2 = $repository->createAdvanceOrderFromOrders(
            [$orderAliace->id, $orderOtero->id],
            '2025-12-05 09:00:00',
            [$productionArea->id]
        );

        // Verify both AOs have the same order lines (setup verification)
        $ao1Lines = AdvanceOrderOrderLine::where('advance_order_id', $advanceOrder1->id)
            ->pluck('order_line_id')
            ->sort()
            ->values()
            ->toArray();

        $ao2Lines = AdvanceOrderOrderLine::where('advance_order_id', $advanceOrder2->id)
            ->pluck('order_line_id')
            ->sort()
            ->values()
            ->toArray();

        // Both AOs should have the same 2 order lines
        $this->assertEquals($ao1Lines, $ao2Lines, 'Both AOs should have the same order lines');
        $this->assertCount(2, $ao1Lines);

        // 12. GENERATE REPORT FOR BOTH ADVANCE ORDERS
        // This is where the bug manifests - quantities get doubled

        $consolidadoRepository = app(ConsolidadoEmplatadoRepository::class);
        $consolidatedData = $consolidadoRepository->getConsolidatedPlatedDishData(
            [$advanceOrder1->id, $advanceOrder2->id]
        );

        // 13. ASSERTIONS - Validate CORRECT behavior (quantities NOT doubled)

        $this->assertCount(1, $consolidatedData); // One product

        $productData = $consolidatedData[0];
        $this->assertEquals($product->id, $productData['product_id']);

        // CRITICAL ASSERTION: Total HORECA should be 59 (20 + 39), NOT 118 (doubled)
        // If this assertion fails, it means the bug still exists
        $this->assertEquals(
            59,
            $productData['total_horeca'],
            'Total HORECA should be 59 (ALIACE 20 + OTERO 39), NOT 118 (doubled). ' .
            'Order lines appearing in multiple AdvanceOrders should only be counted ONCE.'
        );

        // Verify ingredient calculations
        $ingredientData = $productData['ingredients'][0];
        $this->assertEquals('MZC - CONSOME DE POLLO GRANEL', $ingredientData['ingredient_name']);

        // Verify per-grouper quantities
        $clientes = collect($ingredientData['clientes'])->keyBy('column_name');

        // ALIACE: Should be 20 portions, NOT 40
        $aliaceData = $clientes['ALIACE HORECA'];
        $this->assertEquals(
            20,
            $aliaceData['porciones'],
            'ALIACE should have 20 portions, NOT 40 (doubled)'
        );
        $this->assertEquals(
            6000, // 20 × 300g
            $aliaceData['gramos'],
            'ALIACE should have 6000g (20 × 300g), NOT 12000g (doubled)'
        );

        // OTERO: Should be 39 portions, NOT 78
        $oteroData = $clientes['OTERO HORECA'];
        $this->assertEquals(
            39,
            $oteroData['porciones'],
            'OTERO should have 39 portions, NOT 78 (doubled)'
        );
        $this->assertEquals(
            11700, // 39 × 300g
            $oteroData['gramos'],
            'OTERO should have 11700g (39 × 300g), NOT 23400g (doubled)'
        );

        // Verify bag calculations for ALIACE (20 × 300g = 6000g)
        // 6000g ÷ 1000g max = 6 bags of 1000g
        $this->assertEquals(
            [1000, 1000, 1000, 1000, 1000, 1000],
            $aliaceData['weights'],
            'ALIACE bags should be 6 × 1000g'
        );

        // Verify bag calculations for OTERO (39 × 300g = 11700g)
        // 11700g ÷ 1000g max = 11 bags of 1000g + 1 bag of 700g
        $this->assertEquals(
            [1000, 1000, 1000, 1000, 1000, 1000, 1000, 1000, 1000, 1000, 1000, 700],
            $oteroData['weights'],
            'OTERO bags should be 11 × 1000g + 1 × 700g'
        );
    }

    /**
     * TDD RED PHASE: Test that flat format columns are aligned with schema order.
     *
     * PRODUCTION BUG SCENARIO (discovered 2025-12-12):
     * ================================================
     * The column "UNICON PANAMERICA HORECA" was showing numeric values (59, 120, etc.)
     * instead of bag descriptions like "8 BOLSAS DE 1000 GRAMOS".
     *
     * ROOT CAUSE:
     * ===========
     * 1. The schema defines columns in grouper display_order: OTERO, ALIACE, LONCHERA, UNICON LE, UNICON PA
     * 2. The transformToFlatFormat() method extracted columns from DATA (alphabetically sorted):
     *    ALIACE, OTERO, UNICON LE, UNICON PA (missing LONCHERA because no data)
     * 3. When Excel calls array_values() on the row, the column positions don't match headers
     * 4. This causes data to shift: UNICON PA column shows total_horeca value instead of bag description
     *
     * EXPECTED BEHAVIOR:
     * ==================
     * The flat format should:
     * 1. Use column order from SCHEMA (not extracted from data)
     * 2. Include ALL schema columns even if no data exists for them
     * 3. Each row's array_values() should match header positions exactly
     *
     * This test creates a scenario with:
     * - 5 groupers matching production order (OTERO, ALIACE, LONCHERA, UNICON LE, UNICON PA)
     * - 1 product with orders only for OTERO and UNICON PA (skipping ALIACE, LONCHERA, UNICON LE)
     * - Validates that flat format row keys match schema keys in exact order
     */
    public function test_flat_format_columns_are_aligned_with_schema_order(): void
    {
        // 1. CREATE PRODUCTION AREA
        $productionArea = ProductionArea::create([
            'name' => 'Cocina HORECA Column Alignment Test',
            'description' => 'Production area for column alignment test',
        ]);

        // 2. CREATE CATEGORY
        $category = Category::create([
            'name' => 'Sopas HORECA Alignment',
            'code' => 'SOP-ALIGN',
            'description' => 'Sopas para test de alineación',
            'active' => true,
        ]);

        // 3. CREATE PRODUCT
        $product = Product::create([
            'name' => 'TEST CONSOME ALIGNMENT',
            'code' => 'TEST-ALIGN-001',
            'description' => 'Test product for column alignment',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        $product->productionAreas()->attach($productionArea->id);

        // 4. CREATE PLATED DISH (150g/PAX)
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - CONSOME GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 150,
            'max_quantity_horeca' => 1000,
            'order_index' => 1,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // 5. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'Lista Column Alignment Test',
            'description' => 'Price list for column alignment test',
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 2500,
        ]);

        // 6. CREATE 5 COMPANIES WITH BRANCHES (emulating production groupers)
        // Order matches production: OTERO (1), ALIACE (2), LONCHERA (5), UNICON LE (6), UNICON PA (7)

        $companyOtero = Company::create([
            'name' => 'TEST OTERO ALIGNMENT S.A.',
            'email' => 'otero-align@test.com',
            'tax_id' => '11111111-A',
            'company_code' => 'T-OTERO-A',
            'fantasy_name' => 'Test Otero Alignment',
            'price_list_id' => $priceList->id,
        ]);
        $branchOtero = Branch::create([
            'company_id' => $companyOtero->id,
            'fantasy_name' => 'OTERO HORECA',
            'branch_code' => 'T-OTERO-A-01',
            'address' => 'Test Address Otero',
            'min_price_order' => 0,
        ]);

        $companyAliace = Company::create([
            'name' => 'TEST ALIACE ALIGNMENT S.A.',
            'email' => 'aliace-align@test.com',
            'tax_id' => '22222222-A',
            'company_code' => 'T-ALIACE-A',
            'fantasy_name' => 'Test Aliace Alignment',
            'price_list_id' => $priceList->id,
        ]);
        $branchAliace = Branch::create([
            'company_id' => $companyAliace->id,
            'fantasy_name' => 'ALIACE HORECA',
            'branch_code' => 'T-ALIACE-A-01',
            'address' => 'Test Address Aliace',
            'min_price_order' => 0,
        ]);

        $companyLonchera = Company::create([
            'name' => 'TEST LONCHERA ALIGNMENT SPA',
            'email' => 'lonchera-align@test.com',
            'tax_id' => '33333333-A',
            'company_code' => 'T-LONCH-A',
            'fantasy_name' => 'Test Lonchera Alignment',
            'price_list_id' => $priceList->id,
        ]);
        $branchLonchera = Branch::create([
            'company_id' => $companyLonchera->id,
            'fantasy_name' => 'LONCHERA',
            'branch_code' => 'T-LONCH-A-01',
            'address' => 'Test Address Lonchera',
            'min_price_order' => 0,
        ]);

        $companyUniconLE = Company::create([
            'name' => 'TEST UNICON LE ALIGNMENT S.A.',
            'email' => 'unicon-le-align@test.com',
            'tax_id' => '44444444-A',
            'company_code' => 'T-UNIC-LE-A',
            'fantasy_name' => 'Test Unicon LE Alignment',
            'price_list_id' => $priceList->id,
        ]);
        $branchUniconLE = Branch::create([
            'company_id' => $companyUniconLE->id,
            'fantasy_name' => 'UNICON LO ESPEJO HORECA',
            'branch_code' => 'T-UNIC-LE-A-01',
            'address' => 'Test Address Unicon LE',
            'min_price_order' => 0,
        ]);

        $companyUniconPA = Company::create([
            'name' => 'TEST UNICON PA ALIGNMENT S.A.',
            'email' => 'unicon-pa-align@test.com',
            'tax_id' => '55555555-A',
            'company_code' => 'T-UNIC-PA-A',
            'fantasy_name' => 'Test Unicon PA Alignment',
            'price_list_id' => $priceList->id,
        ]);
        $branchUniconPA = Branch::create([
            'company_id' => $companyUniconPA->id,
            'fantasy_name' => 'UNICON PANAMERICA HORECA',
            'branch_code' => 'T-UNIC-PA-A-01',
            'address' => 'Test Address Unicon PA',
            'min_price_order' => 0,
        ]);

        // 7. CREATE REPORT GROUPERS (in production order with display_order)
        $this->createGroupersByName([
            ['name' => 'OTERO HORECA', 'company_id' => $companyOtero->id, 'display_order' => 1],
            ['name' => 'ALIACE HORECA', 'company_id' => $companyAliace->id, 'display_order' => 2],
            ['name' => 'LONCHERA', 'company_id' => $companyLonchera->id, 'display_order' => 5],
            ['name' => 'UNICON LO ESPEJO HORECA', 'company_id' => $companyUniconLE->id, 'display_order' => 6],
            ['name' => 'UNICON PANAMERICA HORECA', 'company_id' => $companyUniconPA->id, 'display_order' => 7],
        ]);

        // 8. CREATE USERS (only for OTERO and UNICON PA - skip others to test missing columns)
        $userOtero = User::create([
            'name' => 'Usuario Otero Alignment',
            'nickname' => 'OTERO.ALIGN',
            'email' => 'otero-align-user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyOtero->id,
            'branch_id' => $branchOtero->id,
        ]);

        $userUniconPA = User::create([
            'name' => 'Usuario Unicon PA Alignment',
            'nickname' => 'UNICONPA.ALIGN',
            'email' => 'unicon-pa-align-user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyUniconPA->id,
            'branch_id' => $branchUniconPA->id,
        ]);

        // 9. CREATE ORDERS (only for OTERO: 10 portions, UNICON PA: 56 portions)
        $orderOtero = Order::create([
            'user_id' => $userOtero->id,
            'branch_id' => $branchOtero->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-05',
            'date' => '2025-12-05',
            'order_number' => 'ORD-OTERO-ALIGN',
            'total' => 25000,
            'total_with_tax' => 29750,
            'tax_amount' => 4750,
            'grand_total' => 29750,
            'dispatch_cost' => 0,
        ]);

        $orderUniconPA = Order::create([
            'user_id' => $userUniconPA->id,
            'branch_id' => $branchUniconPA->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-05',
            'date' => '2025-12-05',
            'order_number' => 'ORD-UNICONPA-ALIGN',
            'total' => 140000,
            'total_with_tax' => 166600,
            'tax_amount' => 26600,
            'grand_total' => 166600,
            'dispatch_cost' => 0,
        ]);

        // 10. CREATE ORDER LINES
        OrderLine::create([
            'order_id' => $orderOtero->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_price' => 2500,
            'subtotal' => 25000,
        ]);

        OrderLine::create([
            'order_id' => $orderUniconPA->id,
            'product_id' => $product->id,
            'quantity' => 56,
            'unit_price' => 2500,
            'subtotal' => 140000,
        ]);

        // 11. CREATE ADVANCE ORDER
        $repository = new OrderRepository();
        $advanceOrder = $repository->createAdvanceOrderFromOrders(
            [$orderOtero->id, $orderUniconPA->id],
            '2025-12-05 08:00:00',
            [$productionArea->id]
        );

        // 12. CONFIGURE SCHEMA WITH COLUMN NAMES FROM REPOSITORY
        $consolidadoRepository = app(ConsolidadoEmplatadoRepository::class);
        $columnNames = $consolidadoRepository->getColumnNamesFromAdvanceOrders([$advanceOrder->id]);

        // Verify column names are in grouper display_order (NOT alphabetical)
        $expectedOrder = ['OTERO HORECA', 'ALIACE HORECA', 'LONCHERA', 'UNICON LO ESPEJO HORECA', 'UNICON PANAMERICA HORECA'];
        $this->assertEquals(
            $expectedOrder,
            $columnNames,
            'Column names should be ordered by grouper display_order, not alphabetically'
        );

        // Configure schema
        ConsolidadoEmplatadoSchema::setClientColumns($columnNames);

        // 13. GET FLAT FORMAT DATA
        $flatData = $consolidadoRepository->getConsolidatedPlatedDishData([$advanceOrder->id], true);

        // 14. VERIFY FLAT FORMAT ROW KEYS MATCH SCHEMA KEYS
        $schemaKeys = ConsolidadoEmplatadoSchema::getHeaderKeys();
        $expectedKeys = [
            'plato',
            'ingrediente',
            'cantidad_x_pax',
            'individual',
            'client_otero_horeca',
            'client_aliace_horeca',
            'client_lonchera',
            'client_unicon_lo_espejo_horeca',
            'client_unicon_panamerica_horeca',
            'total_horeca',
            'total_bolsas',
        ];

        $this->assertEquals($expectedKeys, $schemaKeys, 'Schema keys should be in correct order');

        // Get first data row (not totals row)
        $dataRow = $flatData[0];
        $dataKeys = array_keys($dataRow);

        // CRITICAL ASSERTION: Row keys must match schema keys in EXACT order
        $this->assertEquals(
            $schemaKeys,
            $dataKeys,
            'Flat format row keys must match schema keys in exact order. ' .
            'If this fails, columns will be misaligned in Excel output.'
        );

        // 15. VERIFY DATA VALUES ARE IN CORRECT POSITIONS
        // When we do array_values(), each position should correspond to the correct header

        // OTERO (position 4): 10 portions × 150g = 1500g → 1 BOLSA DE 1000 GRAMOS, 1 BOLSA DE 500 GRAMOS
        $this->assertStringContainsString(
            'BOLSA',
            $dataRow['client_otero_horeca'],
            'OTERO HORECA column should contain bag description'
        );

        // ALIACE (position 5): No data, should be empty
        $this->assertEquals(
            '',
            $dataRow['client_aliace_horeca'],
            'ALIACE HORECA column should be empty (no orders)'
        );

        // LONCHERA (position 6): No data, should be empty
        $this->assertEquals(
            '',
            $dataRow['client_lonchera'],
            'LONCHERA column should be empty (no orders)'
        );

        // UNICON LO ESPEJO (position 7): No data, should be empty
        $this->assertEquals(
            '',
            $dataRow['client_unicon_lo_espejo_horeca'],
            'UNICON LO ESPEJO HORECA column should be empty (no orders)'
        );

        // UNICON PANAMERICA (position 8): 56 portions × 150g = 8400g → 8 BOLSAS DE 1000 GRAMOS, 1 BOLSA DE 400 GRAMOS
        $this->assertStringContainsString(
            'BOLSA',
            $dataRow['client_unicon_panamerica_horeca'],
            'UNICON PANAMERICA HORECA column should contain bag description, NOT a number'
        );

        // TOTAL HORECA (position 9): Should be a number (66)
        $this->assertEquals(
            '66',
            $dataRow['total_horeca'],
            'TOTAL HORECA should be 66 (10 + 56)'
        );

        // 16. FINAL VERIFICATION: array_values() should produce correct Excel row
        $valuesRow = array_values($dataRow);
        $headerValues = array_values(ConsolidadoEmplatadoSchema::getHeaders());

        // Position 8 (UNICON PANAMERICA HORECA) should NOT be a plain number
        $uniconPAPosition = array_search('UNICON PANAMERICA HORECA', $headerValues);
        $this->assertNotNull($uniconPAPosition, 'UNICON PANAMERICA HORECA should exist in headers');

        $uniconPAValue = $valuesRow[$uniconPAPosition];
        $this->assertStringContainsString(
            'BOLSA',
            $uniconPAValue,
            "Position {$uniconPAPosition} (UNICON PANAMERICA HORECA) should contain bag description, " .
            "got: '{$uniconPAValue}'. This indicates column misalignment."
        );

        // Position 9 (TOTAL HORECA) should be a number
        $totalHorecaPosition = array_search('TOTAL HORECA', $headerValues);
        $totalHorecaValue = $valuesRow[$totalHorecaPosition];
        $this->assertEquals(
            '66',
            $totalHorecaValue,
            "Position {$totalHorecaPosition} (TOTAL HORECA) should be '66', " .
            "got: '{$totalHorecaValue}'. This indicates column misalignment."
        );
    }

    /**
     * TDD RED PHASE: Test that INDIVIDUAL, TOTAL HORECA, and TOTAL BOLSAS cells
     * are merged across all ingredient rows for a product.
     *
     * PRODUCTION BUG SCENARIO (discovered 2025-12-12):
     * ================================================
     * Product: HORECA TOMATICAN DE VACUNO CON ARROZ CASERO
     * - Has 3 ingredients
     * - INDIVIDUAL column shows "100" repeated 3 times (one per ingredient row)
     * - TOTAL HORECA column shows "95" repeated 3 times
     * - TOTAL BOLSAS column shows the same bags description 3 times
     *
     * EXPECTED BEHAVIOR:
     * ==================
     * These columns should show the value ONCE and visually span/merge all ingredient rows:
     * - INDIVIDUAL: value appears in first ingredient row, merged across all 3 rows
     * - TOTAL HORECA: value appears in first ingredient row, merged across all 3 rows
     * - TOTAL BOLSAS: value appears in first ingredient row, merged across all 3 rows
     *
     * This is similar to how PLATO column already works - it merges across ingredient rows.
     *
     * IMPLEMENTATION APPROACH:
     * ========================
     * 1. Repository: In transformToFlatFormat(), only put values in first ingredient row,
     *    empty string for subsequent rows (like PLATO column already does)
     * 2. Export: In AfterSheet event, merge cells in these columns for each product group
     *    (reuse the existing productRowGroups tracking used for PLATO merging)
     */
    public function test_individual_total_horeca_and_total_bolsas_are_merged_across_ingredient_rows(): void
    {
        // 1. CREATE PRODUCTION AREA
        $productionArea = ProductionArea::create([
            'name' => 'Cocina HORECA Merge Test',
            'description' => 'Production area for merge cells test',
        ]);

        // 2. CREATE CATEGORY
        $category = Category::create([
            'name' => 'Platos HORECA Merge',
            'code' => 'PH-MERGE',
            'description' => 'Platos para test de celdas combinadas',
            'active' => true,
        ]);

        // 3. CREATE PRODUCT
        $product = Product::create([
            'name' => 'TEST HORECA PRODUCT WITH 3 INGREDIENTS',
            'code' => 'TEST-MERGE-001',
            'description' => 'Test product for merge cells validation',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        $product->productionAreas()->attach($productionArea->id);

        // 4. CREATE PLATED DISH with 3 INGREDIENTS
        // This is key: the product has multiple ingredients to test merging
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // Ingredient 1
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - INGREDIENTE UNO',
            'measure_unit' => 'GR',
            'quantity' => 100,
            'max_quantity_horeca' => 1000,
            'order_index' => 1,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // Ingredient 2
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - INGREDIENTE DOS',
            'measure_unit' => 'GR',
            'quantity' => 150,
            'max_quantity_horeca' => 1000,
            'order_index' => 2,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // Ingredient 3
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - INGREDIENTE TRES',
            'measure_unit' => 'GR',
            'quantity' => 200,
            'max_quantity_horeca' => 1000,
            'order_index' => 3,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // 5. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'Lista Merge Test',
            'description' => 'Price list for merge cells test',
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 5000,
        ]);

        // 6. CREATE COMPANY AND BRANCH
        $company = Company::create([
            'name' => 'TEST MERGE CELLS S.A.',
            'email' => 'merge-test@test.com',
            'tax_id' => '88888888-M',
            'company_code' => 'T-MERGE',
            'fantasy_name' => 'Test Merge Cells',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'fantasy_name' => 'MERGE TEST HORECA',
            'branch_code' => 'T-MERGE-01',
            'address' => 'Test Address Merge',
            'min_price_order' => 0,
        ]);

        // 7. CREATE REPORT GROUPER
        $this->createGroupersByName([
            ['name' => 'MERGE TEST HORECA', 'company_id' => $company->id, 'display_order' => 1],
        ]);

        // 8. CREATE USER
        $user = User::create([
            'name' => 'Usuario Merge Test',
            'nickname' => 'MERGE.TEST',
            'email' => 'merge-test-user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        // 9. CREATE ORDER with 10 portions
        $order = Order::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-05',
            'date' => '2025-12-05',
            'order_number' => 'ORD-MERGE-TEST',
            'total' => 50000,
            'total_with_tax' => 59500,
            'tax_amount' => 9500,
            'grand_total' => 59500,
            'dispatch_cost' => 0,
        ]);

        // 10. CREATE ORDER LINE
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 10, // 10 portions
            'unit_price' => 5000,
            'subtotal' => 50000,
        ]);

        // 11. CREATE ADVANCE ORDER
        $repository = new OrderRepository();
        $advanceOrder = $repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-12-05 08:00:00',
            [$productionArea->id]
        );

        // 12. CONFIGURE SCHEMA AND GET FLAT FORMAT DATA
        $consolidadoRepository = app(ConsolidadoEmplatadoRepository::class);
        $columnNames = $consolidadoRepository->getColumnNamesFromAdvanceOrders([$advanceOrder->id]);
        ConsolidadoEmplatadoSchema::setClientColumns($columnNames);

        $flatData = $consolidadoRepository->getConsolidatedPlatedDishData([$advanceOrder->id], true);

        // 13. VERIFY WE HAVE 3 INGREDIENT ROWS + 1 TOTALS ROW
        $this->assertCount(4, $flatData, 'Should have 3 ingredient rows + 1 totals row');

        // 14. VERIFY CURRENT BUG: Values are repeated in all rows
        // Row 1: First ingredient
        $row1 = $flatData[0];
        // Row 2: Second ingredient
        $row2 = $flatData[1];
        // Row 3: Third ingredient
        $row3 = $flatData[2];

        // NOTE: TOTAL BOLSAS is CORRECTLY different for each ingredient
        // Each ingredient has its own bag calculation, so this is NOT merged
        $this->assertNotEquals($row1['total_bolsas'], $row2['total_bolsas'], 'TOTAL BOLSAS should be different per ingredient');

        // ====================================================================
        // EXPECTED BEHAVIOR - Values shown only in first row for merge
        // ====================================================================

        // INDIVIDUAL: Only first row should have value, others should be empty (for merge)
        $this->assertEquals(
            '0', // HORECA products always have 0 individual
            $row1['individual'],
            'First row should have INDIVIDUAL value'
        );
        $this->assertEquals(
            '',
            $row2['individual'],
            'Second ingredient row INDIVIDUAL should be empty (for merge). ' .
            'Currently shows: "' . $row2['individual'] . '"'
        );
        $this->assertEquals(
            '',
            $row3['individual'],
            'Third ingredient row INDIVIDUAL should be empty (for merge). ' .
            'Currently shows: "' . $row3['individual'] . '"'
        );

        // TOTAL HORECA: Only first row should have value, others should be empty (for merge)
        $this->assertEquals(
            '10', // 10 portions
            $row1['total_horeca'],
            'First row should have TOTAL HORECA value (10 portions)'
        );
        $this->assertEquals(
            '',
            $row2['total_horeca'],
            'Second ingredient row TOTAL HORECA should be empty (for merge). ' .
            'Currently shows: "' . $row2['total_horeca'] . '"'
        );
        $this->assertEquals(
            '',
            $row3['total_horeca'],
            'Third ingredient row TOTAL HORECA should be empty (for merge). ' .
            'Currently shows: "' . $row3['total_horeca'] . '"'
        );

        // TOTAL BOLSAS: Each ingredient has its own value (NOT merged)
        // This is correct behavior - each ingredient has different bag calculations
        $this->assertNotEmpty($row1['total_bolsas'], 'First ingredient should have TOTAL BOLSAS');
        $this->assertNotEmpty($row2['total_bolsas'], 'Second ingredient should have TOTAL BOLSAS');
        $this->assertNotEmpty($row3['total_bolsas'], 'Third ingredient should have TOTAL BOLSAS');

        // ====================================================================
        // EXCEL MERGE CELLS VALIDATION
        // ====================================================================
        // Generate Excel file and verify cells are actually merged

        // Reset schema before export
        ConsolidadoEmplatadoSchema::resetClientColumns();

        // Create export instance
        $export = new ConsolidadoEmplatadoDataExport(collect([$advanceOrder->id]), $columnNames, 999);

        // Generate Excel file
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $fileName = 'test-exports/merge-cells-test-' . now()->format('Y-m-d-His') . '.xlsx';
        $filePath = storage_path('app/' . $fileName);
        $export->store($fileName, 'local');

        // Load Excel file
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Get merge cells ranges
        $mergeCells = $sheet->getMergeCells();

        // Data starts at row 4 (row 1=title, row 2=date, row 3=headers)
        // Product has 3 ingredients, so rows 4, 5, 6
        // Row 7 would be totals row

        // INDIVIDUAL column is column D (4th column)
        // TOTAL HORECA column is column F (6th column) - after client column E

        // Find column indices
        $headers = ConsolidadoEmplatadoSchema::getHeaders();
        $headerKeys = array_keys($headers);
        $individualColIndex = array_search('individual', $headerKeys) + 1; // 1-based
        $totalHorecaColIndex = array_search('total_horeca', $headerKeys) + 1;

        // Convert to Excel column letters
        $individualColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($individualColIndex);
        $totalHorecaColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalHorecaColIndex);

        // Expected merge ranges for 3 ingredient rows (rows 4, 5, 6)
        $expectedIndividualMerge = "{$individualColLetter}4:{$individualColLetter}6";
        $expectedTotalHorecaMerge = "{$totalHorecaColLetter}4:{$totalHorecaColLetter}6";

        // Verify INDIVIDUAL column is merged across ingredient rows
        $this->assertContains(
            $expectedIndividualMerge,
            $mergeCells,
            "INDIVIDUAL column ({$individualColLetter}) should be merged across rows 4-6. " .
            "Expected merge range: {$expectedIndividualMerge}. " .
            "Current merge cells: " . implode(', ', $mergeCells)
        );

        // Verify TOTAL HORECA column is merged across ingredient rows
        $this->assertContains(
            $expectedTotalHorecaMerge,
            $mergeCells,
            "TOTAL HORECA column ({$totalHorecaColLetter}) should be merged across rows 4-6. " .
            "Expected merge range: {$expectedTotalHorecaMerge}. " .
            "Current merge cells: " . implode(', ', $mergeCells)
        );

        // Print file path for manual verification
        echo "\n\n";
        echo "====================================================================\n";
        echo "MERGE CELLS TEST - Excel file generated\n";
        echo "====================================================================\n";
        echo "File: {$filePath}\n";
        echo "Expected INDIVIDUAL merge: {$expectedIndividualMerge}\n";
        echo "Expected TOTAL HORECA merge: {$expectedTotalHorecaMerge}\n";
        echo "Current merge cells: " . implode(', ', $mergeCells) . "\n";
        echo "====================================================================\n";
    }

    /**
     * TDD RED PHASE: Test that bag descriptions use the ingredient's measure unit.
     *
     * PRODUCTION BUG SCENARIO (discovered 2025-12-12):
     * ================================================
     * Product: PCFH - HORECA LASANA GOURMET BOLONESA
     * Ingredient: MZC - LASAÑA BOLOÑESA GRANEL
     *
     * The ingredient has:
     * - quantity: 1 (per portion)
     * - measure_unit: UND (UNIDAD)
     * - max_quantity_horeca: 10
     *
     * The Excel report shows: "1 BOLSA DE 1 GRAMOS"
     * But it SHOULD show: "1 BOLSA DE 1 UNIDADES" or "1 BOLSA DE 1 UND"
     *
     * ROOT CAUSE:
     * ===========
     * The `formatBagDescription()` method in ConsolidadoEmplatadoRepository has "GRAMOS"
     * hardcoded in the output string. It receives `$measureUnit` as a parameter but
     * completely ignores it.
     *
     * Code at line 506:
     *   $parts[] = "1 BOLSA DE {$weight} GRAMOS";  // <-- "GRAMOS" is hardcoded
     *
     * EXPECTED BEHAVIOR:
     * ==================
     * The bag description should use the actual measure unit from the ingredient:
     * - For GR: "1 BOLSA DE 1000 GRAMOS"
     * - For UND: "1 BOLSA DE 5 UNIDADES"
     * - For KG: "1 BOLSA DE 2 KILOGRAMOS"
     * - For LT: "1 BOLSA DE 3 LITROS"
     *
     * This test creates:
     * - 1 product with an ingredient measured in UND (not GR)
     * - Validates that the bag description uses "UNIDADES" instead of "GRAMOS"
     */
    public function test_bag_description_uses_ingredient_measure_unit(): void
    {
        // 1. CREATE PRODUCTION AREA
        $productionArea = ProductionArea::create([
            'name' => 'Cocina HORECA Measure Unit Test',
            'description' => 'Production area for measure unit test',
        ]);

        // 2. CREATE CATEGORY
        $category = Category::create([
            'name' => 'Platos Preparados HORECA',
            'code' => 'PP-MU',
            'description' => 'Platos preparados para test de unidad de medida',
            'active' => true,
        ]);

        // 3. CREATE PRODUCT (simulating LASAÑA BOLOÑESA)
        $product = Product::create([
            'name' => 'TEST HORECA LASANA GOURMET BOLONESA',
            'code' => 'TEST-LASANA-001',
            'description' => 'Test product for measure unit validation',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        $product->productionAreas()->attach($productionArea->id);

        // 4. CREATE PLATED DISH with ingredient in UNIDADES (not GRAMOS)
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // CRITICAL: This ingredient is measured in UND, not GR
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - LASAÑA BOLOÑESA GRANEL',
            'measure_unit' => 'UND', // <-- UNIDADES, not GRAMOS
            'quantity' => 1, // 1 unit per portion
            'max_quantity_horeca' => 10, // max 10 units per bag
            'order_index' => 1,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // 5. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'Lista Measure Unit Test',
            'description' => 'Price list for measure unit test',
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 5000,
        ]);

        // 6. CREATE COMPANY AND BRANCH
        $company = Company::create([
            'name' => 'TEST MEASURE UNIT S.A.',
            'email' => 'measure-unit@test.com',
            'tax_id' => '99999999-M',
            'company_code' => 'T-MEASURE',
            'fantasy_name' => 'Test Measure Unit',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'fantasy_name' => 'MEASURE UNIT HORECA',
            'branch_code' => 'T-MEASURE-01',
            'address' => 'Test Address Measure Unit',
            'min_price_order' => 0,
        ]);

        // 7. CREATE REPORT GROUPER
        $this->createGroupersByName([
            ['name' => 'MEASURE UNIT HORECA', 'company_id' => $company->id, 'display_order' => 1],
        ]);

        // 8. CREATE USER
        $user = User::create([
            'name' => 'Usuario Measure Unit Test',
            'nickname' => 'MEASURE.UNIT',
            'email' => 'measure-unit-user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        // 9. CREATE ORDER with 5 portions
        // 5 portions × 1 UND/portion = 5 UNIDADES total
        // max_quantity_horeca = 10, so: 1 BOLSA DE 5 UNIDADES
        $order = Order::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-05',
            'date' => '2025-12-05',
            'order_number' => 'ORD-MEASURE-UNIT',
            'total' => 25000,
            'total_with_tax' => 29750,
            'tax_amount' => 4750,
            'grand_total' => 29750,
            'dispatch_cost' => 0,
        ]);

        // 10. CREATE ORDER LINE
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 5, // 5 portions
            'unit_price' => 5000,
            'subtotal' => 25000,
        ]);

        // 11. CREATE ADVANCE ORDER
        $repository = new OrderRepository();
        $advanceOrder = $repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-12-05 08:00:00',
            [$productionArea->id]
        );

        // 12. GET CONSOLIDATED DATA
        $consolidadoRepository = app(ConsolidadoEmplatadoRepository::class);
        $consolidatedData = $consolidadoRepository->getConsolidatedPlatedDishData([$advanceOrder->id]);

        // 13. ASSERTIONS
        $this->assertCount(1, $consolidatedData);

        $productData = $consolidatedData[0];
        $ingredientData = $productData['ingredients'][0];

        // Verify measure unit is UND
        $this->assertEquals('UND', $ingredientData['measure_unit']);

        // Get the client column data
        $clientData = $ingredientData['clientes'][0];

        // Verify calculations are correct
        $this->assertEquals(5, $clientData['porciones']); // 5 portions
        $this->assertEquals(5, $clientData['gramos']); // 5 × 1 UND = 5 (named gramos but it's actually units)
        $this->assertEquals([5], $clientData['weights']); // 1 bag with 5 units

        // CRITICAL ASSERTION: Description should use UNIDADES, not GRAMOS
        // Currently fails because formatBagDescription() hardcodes "GRAMOS"
        $descripcion = $clientData['descripcion'];

        $this->assertStringNotContainsString(
            'GRAMOS',
            implode(' ', $descripcion),
            'Bag description should NOT contain "GRAMOS" for an ingredient measured in UND. ' .
            'The measure unit is UND but the output shows "GRAMOS".'
        );

        $this->assertStringContainsString(
            'UNIDADES',
            implode(' ', $descripcion),
            'Bag description should contain "UNIDADES" for an ingredient with measure_unit=UND. ' .
            'Expected: "1 BOLSA DE 5 UNIDADES", Got: "' . implode(', ', $descripcion) . '"'
        );
    }

    /**
     * TDD RED PHASE: Test that HORECA labels only include products from the AdvanceOrder,
     * not ALL products from the related orders.
     *
     * PRODUCTION BUG SCENARIO (discovered 2025-12-12):
     * ================================================
     * OP #101 was created with specific production areas (EMPLATADO, CUARTO FRIO, etc.)
     * but NOT "CUARTO CALIENTE".
     *
     * When generating HORECA labels for OP #101:
     * - The report (ConsolidadoEmplatadoRepository) correctly shows only 1 product:
     *   "ACM - HORECA MINI ENSALADA CHOCLO Y PEPINO" (belongs to EMPLATADO area)
     *
     * - But the labels (HorecaLabelDataRepository) incorrectly show 12 products,
     *   including products from "CUARTO CALIENTE" that are NOT in the OP.
     *
     * ROOT CAUSE:
     * ===========
     * HorecaLabelDataRepository uses:
     *   Order::whereIn('id', $orderIds)->with(['orderLines...'])
     *
     * This loads ALL order lines from those orders, ignoring the production area filter
     * that was applied when creating the AdvanceOrder.
     *
     * ConsolidadoEmplatadoRepository correctly uses:
     *   AdvanceOrder::whereIn('id', $advanceOrderIds)->with(['associatedOrderLines...'])
     *
     * This only loads the order lines that are actually in the OP.
     *
     * EXPECTED BEHAVIOR:
     * ==================
     * HorecaLabelDataRepository should only generate labels for products that are
     * actually in the AdvanceOrder (i.e., filtered by production area).
     *
     * TEST SCENARIO:
     * ==============
     * - Create 2 production areas: "EMPLATADO" and "CUARTO CALIENTE"
     * - Create 2 HORECA products:
     *   1. "ENSALADA HORECA" -> belongs to EMPLATADO
     *   2. "SOPA HORECA" -> belongs to CUARTO CALIENTE
     * - Create 1 order with both products
     * - Create AdvanceOrder with ONLY "EMPLATADO" area
     * - Generate labels -> should only have "ENSALADA HORECA", NOT "SOPA HORECA"
     */
    public function test_horeca_labels_only_include_products_from_advance_order_production_areas(): void
    {
        // 1. CREATE TWO PRODUCTION AREAS
        $areaEmplatado = ProductionArea::create([
            'name' => 'EMPLATADO TEST',
            'description' => 'Área de emplatado para test',
        ]);

        $areaCuartoCaliente = ProductionArea::create([
            'name' => 'CUARTO CALIENTE TEST',
            'description' => 'Área de cuarto caliente para test',
        ]);

        // 2. CREATE CATEGORY
        $category = Category::create([
            'name' => 'Platos HORECA Label Test',
            'code' => 'PH-LABEL',
            'description' => 'Platos para test de etiquetas',
            'active' => true,
        ]);

        // 3. CREATE TWO HORECA PRODUCTS - each in different production area

        // Product 1: ENSALADA -> EMPLATADO (should be in labels)
        $productEnsalada = Product::create([
            'name' => 'TEST HORECA ENSALADA EMPLATADO',
            'code' => 'TEST-ENS-EMPL',
            'description' => 'Ensalada HORECA en área EMPLATADO',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);
        $productEnsalada->productionAreas()->attach($areaEmplatado->id);

        $platedDishEnsalada = PlatedDish::create([
            'product_id' => $productEnsalada->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishEnsalada->id,
            'ingredient_name' => 'MZC - LECHUGA PARA ENSALADA',
            'measure_unit' => 'GR',
            'quantity' => 100,
            'max_quantity_horeca' => 1000,
            'order_index' => 1,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // Product 2: SOPA -> CUARTO CALIENTE (should NOT be in labels)
        $productSopa = Product::create([
            'name' => 'TEST HORECA SOPA CUARTO CALIENTE',
            'code' => 'TEST-SOPA-CC',
            'description' => 'Sopa HORECA en área CUARTO CALIENTE',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);
        $productSopa->productionAreas()->attach($areaCuartoCaliente->id);

        $platedDishSopa = PlatedDish::create([
            'product_id' => $productSopa->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishSopa->id,
            'ingredient_name' => 'MZC - CALDO DE POLLO PARA SOPA',
            'measure_unit' => 'GR',
            'quantity' => 300,
            'max_quantity_horeca' => 1000,
            'order_index' => 1,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // 4. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'Lista Label Test',
            'description' => 'Price list for label test',
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productEnsalada->id,
            'unit_price' => 3000,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productSopa->id,
            'unit_price' => 2500,
        ]);

        // 5. CREATE COMPANY AND BRANCH
        $company = Company::create([
            'name' => 'TEST LABEL FILTER S.A.',
            'email' => 'label-filter@test.com',
            'tax_id' => '77777777-L',
            'company_code' => 'T-LABEL',
            'fantasy_name' => 'Test Label Filter',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'fantasy_name' => 'LABEL TEST BRANCH',
            'branch_code' => 'T-LABEL-01',
            'address' => 'Test Address Label',
            'min_price_order' => 0,
        ]);

        // 6. CREATE USER
        $user = User::create([
            'name' => 'Usuario Label Test',
            'nickname' => 'LABEL.TEST',
            'email' => 'label-test-user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        // 7. CREATE ORDER WITH BOTH PRODUCTS
        $order = Order::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-05',
            'date' => '2025-12-05',
            'order_number' => 'ORD-LABEL-TEST',
            'total' => 55000,
            'total_with_tax' => 65450,
            'tax_amount' => 10450,
            'grand_total' => 65450,
            'dispatch_cost' => 0,
        ]);

        // Order line 1: ENSALADA (10 portions)
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productEnsalada->id,
            'quantity' => 10,
            'unit_price' => 3000,
            'subtotal' => 30000,
        ]);

        // Order line 2: SOPA (10 portions)
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productSopa->id,
            'quantity' => 10,
            'unit_price' => 2500,
            'subtotal' => 25000,
        ]);

        // 8. CREATE ADVANCE ORDER WITH ONLY "EMPLATADO" AREA
        // This should only include the ENSALADA product, not SOPA
        $repository = new OrderRepository();
        $advanceOrder = $repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-12-05 08:00:00',
            [$areaEmplatado->id] // ONLY EMPLATADO area - excludes CUARTO CALIENTE
        );

        // 9. VERIFY ADVANCE ORDER ONLY HAS ENSALADA PRODUCT
        $aoLines = $advanceOrder->associatedOrderLines()->with('orderLine.product')->get();
        $productsInAO = $aoLines->pluck('orderLine.product.name')->unique()->values()->toArray();

        $this->assertCount(1, $productsInAO, 'AdvanceOrder should only have 1 product (ENSALADA)');
        $this->assertContains('TEST HORECA ENSALADA EMPLATADO', $productsInAO);
        $this->assertNotContains('TEST HORECA SOPA CUARTO CALIENTE', $productsInAO);

        // 10. CALL HorecaLabelDataRepository with the NEW method (fixed)
        // The new method getHorecaLabelDataByAdvanceOrder() uses associatedOrderLines
        // which respects the production area filter
        $horecaLabelRepository = app(\App\Contracts\HorecaLabelDataRepositoryInterface::class);
        $labelData = $horecaLabelRepository->getHorecaLabelDataByAdvanceOrder($advanceOrder->id);

        // 12. ASSERTIONS - EXPECTED BEHAVIOR (will fail with current bug)

        // Get unique ingredient names from label data
        $ingredientNames = $labelData->pluck('ingredient_name')->unique()->values()->toArray();

        // CRITICAL ASSERTION: Labels should ONLY have ENSALADA ingredient
        $this->assertContains(
            'MZC - LECHUGA PARA ENSALADA',
            $ingredientNames,
            'Labels should contain ENSALADA ingredient (product is in EMPLATADO area which is in OP)'
        );

        // CRITICAL ASSERTION: Labels should NOT have SOPA ingredient
        // This assertion will FAIL because HorecaLabelDataRepository includes ALL products
        $this->assertNotContains(
            'MZC - CALDO DE POLLO PARA SOPA',
            $ingredientNames,
            'Labels should NOT contain SOPA ingredient. ' .
            'SOPA product belongs to CUARTO CALIENTE area which is NOT in the AdvanceOrder. ' .
            'Current bug: HorecaLabelDataRepository loads ALL order lines from orders, ' .
            'ignoring the production area filter applied when creating the AdvanceOrder. ' .
            'Found ingredients: ' . implode(', ', $ingredientNames)
        );

        // Additional assertion: verify only 1 unique ingredient
        $this->assertCount(
            1,
            $ingredientNames,
            'Should have exactly 1 ingredient (LECHUGA from ENSALADA). ' .
            'Found ' . count($ingredientNames) . ' ingredients: ' . implode(', ', $ingredientNames)
        );
    }

    /**
     * Test that HORECA labels are grouped by ReportGrouper instead of by Branch.
     *
     * CURRENT BUG:
     * HorecaLabelDataRepository groups labels by branch_id + ingredient_name.
     * This creates separate labels for each branch even when multiple branches
     * belong to the same ReportGrouper.
     *
     * Example from production (OP #101):
     * - Branch 256 (COMERCIAL HEY) → 3 labels
     * - Branch 258 (EXACTO) → 3 labels
     * - Branch 268 (INVEROTERO) → 3 labels
     * - Branch 270 (SERVIPER) → 5 labels
     * - Branch 273 (OTERO) → 3 labels
     * ALL 5 branches belong to "OTERO HORECA" grouper and should be consolidated.
     *
     * EXPECTED BEHAVIOR:
     * Labels should be grouped by grouper_name + ingredient_name, consolidating
     * all branches that belong to the same ReportGrouper into a single label.
     *
     * COMPONENTS TO FIX:
     * 1. HorecaLabelDataRepository - group by ReportGrouper instead of branch
     * 2. HorecaLabelService - use grouper_name instead of branch_fantasy_name
     * 3. HorecaLabelGenerator - display grouper name on label
     */
    public function test_horeca_labels_are_grouped_by_report_grouper_instead_of_branch(): void
    {
        // 1. CREATE PRODUCTION AREA
        $areaEmplatado = ProductionArea::firstOrCreate(
            ['name' => 'EMPLATADO'],
            ['description' => 'Área de emplatado', 'active' => true]
        );

        // 2. CREATE CATEGORY
        $category = Category::create([
            'name' => 'HORECA GROUPER TEST',
            'code' => 'HGT',
            'description' => 'Category for grouper test',
        ]);

        // 3. CREATE HORECA PRODUCT WITH PLATED DISH
        $product = Product::create([
            'name' => 'TEST HORECA ENSALADA GROUPER',
            'code' => 'T-HORECA-GRP',
            'description' => 'Test HORECA product for grouper test',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Attach product to production area (many-to-many relationship)
        $product->productionAreas()->attach($areaEmplatado->id);

        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'MZC - LECHUGA GROUPER TEST',
            'quantity' => 20, // 20 GR per portion
            'measure_unit' => 'GR',
            'max_quantity_horeca' => 500,
            'shelf_life' => 5,
            'order_index' => 1,
            'is_optional' => false,
        ]);

        // 4. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'Lista Grouper Test',
            'description' => 'Price list for grouper test',
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 3000,
        ]);

        // 5. CREATE REPORT GROUPER
        // Get or create a report configuration first
        $reportConfig = \App\Models\ReportConfiguration::firstOrCreate(
            ['name' => 'TEST REPORT CONFIG'],
            ['description' => 'Test configuration for grouper test', 'is_active' => true]
        );

        $grouper = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'TEST GROUPER CONSOLIDADO',
            'code' => 'TGC',
            'display_order' => 99,
            'is_active' => true,
        ]);

        // 6. CREATE 3 COMPANIES AND BRANCHES - ALL BELONGING TO SAME GROUPER
        $companiesData = [
            ['name' => 'COMPANY ALPHA S.A.', 'tax_id' => '11111111-A', 'code' => 'ALPHA'],
            ['name' => 'COMPANY BETA S.A.', 'tax_id' => '22222222-B', 'code' => 'BETA'],
            ['name' => 'COMPANY GAMMA S.A.', 'tax_id' => '33333333-G', 'code' => 'GAMMA'],
        ];

        $branches = [];
        $users = [];
        $orders = [];

        foreach ($companiesData as $index => $companyData) {
            $company = Company::create([
                'name' => $companyData['name'],
                'email' => strtolower($companyData['code']) . '@test.com',
                'tax_id' => $companyData['tax_id'],
                'company_code' => 'T-' . $companyData['code'],
                'fantasy_name' => $companyData['name'],
                'price_list_id' => $priceList->id,
            ]);

            // Associate company with grouper
            $grouper->companies()->attach($company->id);

            $branch = Branch::create([
                'company_id' => $company->id,
                'fantasy_name' => 'BRANCH ' . $companyData['code'],
                'branch_code' => 'T-' . $companyData['code'] . '-01',
                'address' => 'Address ' . $companyData['code'],
                'min_price_order' => 0,
            ]);
            $branches[] = $branch;

            $user = User::create([
                'name' => 'User ' . $companyData['code'],
                'nickname' => 'USER.' . $companyData['code'],
                'email' => 'user-' . strtolower($companyData['code']) . '@test.com',
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch->id,
            ]);
            $users[] = $user;

            // Create order with different quantities for each company
            $quantity = ($index + 1) * 10; // 10, 20, 30 portions
            $order = Order::create([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'status' => OrderStatus::PROCESSED,
                'dispatch_date' => '2025-12-05',
                'date' => '2025-12-05',
                'order_number' => 'ORD-GRP-' . $companyData['code'],
                'total' => $quantity * 3000,
                'total_with_tax' => $quantity * 3570,
                'tax_amount' => $quantity * 570,
                'grand_total' => $quantity * 3570,
                'dispatch_cost' => 0,
            ]);
            $orders[] = $order;

            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 3000,
                'subtotal' => $quantity * 3000,
            ]);
        }

        // 7. CREATE ADVANCE ORDER WITH ALL 3 ORDERS
        $repository = new OrderRepository();
        $advanceOrder = $repository->createAdvanceOrderFromOrders(
            collect($orders)->pluck('id')->toArray(),
            '2025-12-05 08:00:00',
            [$areaEmplatado->id]
        );

        // 8. CALL HorecaLabelDataRepository
        $horecaLabelRepository = app(\App\Contracts\HorecaLabelDataRepositoryInterface::class);
        $labelData = $horecaLabelRepository->getHorecaLabelDataByAdvanceOrder($advanceOrder->id);

        // ========== ASSERTIONS FOR HorecaLabelDataRepository ==========

        // ASSERTION 1: Should have only 1 group (all 3 branches consolidated into 1 grouper)
        // CURRENT BUG: Will have 3 groups (one per branch)
        $this->assertCount(
            1,
            $labelData,
            'Should have exactly 1 label group (consolidated by ReportGrouper). ' .
            'CURRENT BUG: Labels are grouped by branch, creating ' . $labelData->count() . ' groups instead of 1. ' .
            'Groups found: ' . $labelData->pluck('branch_fantasy_name')->implode(', ')
        );

        // ASSERTION 2: Group should use grouper_name instead of branch_fantasy_name
        $firstItem = $labelData->first();
        $this->assertArrayHasKey(
            'grouper_name',
            $firstItem,
            'Label data should have grouper_name field instead of branch_fantasy_name'
        );
        $this->assertEquals(
            'TEST GROUPER CONSOLIDADO',
            $firstItem['grouper_name'],
            'Grouper name should be "TEST GROUPER CONSOLIDADO"'
        );

        // ASSERTION 3: Total quantity should be consolidated (10+20+30 portions × 20gr = 1200gr)
        $this->assertEquals(
            1200,
            $firstItem['total_quantity_needed'],
            'Total quantity should be 1200gr (60 portions × 20gr per portion). ' .
            'Found: ' . $firstItem['total_quantity_needed']
        );

        // ASSERTION 4: Labels count should be based on consolidated quantity
        // 1200gr with max 500gr = 3 labels [500, 500, 200]
        $this->assertEquals(
            3,
            $firstItem['labels_count'],
            'Should have 3 labels (1200gr / 500gr max = 3 labels). Found: ' . $firstItem['labels_count']
        );

        // ========== ASSERTIONS FOR HorecaLabelService ==========

        // Test that expandLabelsWithWeights uses grouper_name
        $service = app(\App\Services\Labels\HorecaLabelService::class);

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('expandLabelsWithWeights');
        $method->setAccessible(true);

        $expandedLabels = $method->invoke($service, $labelData);

        // ASSERTION 5: Expanded labels should have grouper_name field
        $this->assertArrayHasKey(
            'grouper_name',
            $expandedLabels->first(),
            'Expanded label should have grouper_name field for HorecaLabelGenerator'
        );

        // ASSERTION 6: All expanded labels should have the same grouper_name
        $grouperNames = $expandedLabels->pluck('grouper_name')->unique();
        $this->assertCount(
            1,
            $grouperNames,
            'All expanded labels should have the same grouper name. Found: ' . $grouperNames->implode(', ')
        );
        $this->assertEquals(
            'TEST GROUPER CONSOLIDADO',
            $grouperNames->first(),
            'Grouper name should be "TEST GROUPER CONSOLIDADO"'
        );

        // Cleanup
        $grouper->companies()->detach();
        $grouper->delete();
    }

    /**
     * TDD RED PHASE: Test that HORECA labels show weights discriminated by product, not consolidated.
     *
     * PRODUCTION BUG SCENARIO (discovered 2025-12-12):
     * ================================================
     * When analyzing OP #125 with PARMESANO ingredient:
     *
     * The Emplatado report shows weights PER PRODUCT:
     * - OTERO HORECA: Product A = 30gr, Product B = 20gr, Product C = 10gr
     * - Total parmesano: 60gr (but shown as 3 separate entries in the report)
     *
     * The HORECA labels currently show CONSOLIDATED weight:
     * - OTERO HORECA: [60] (single consolidated weight)
     *
     * EXPECTED BEHAVIOR:
     * ==================
     * The HORECA labels should match the Emplatado report discrimination:
     * - OTERO HORECA: [30, 20, 10] (discriminated by product)
     *
     * This matters because:
     * 1. Each product may have different preparation requirements
     * 2. Production staff needs to know how much ingredient goes to each dish
     * 3. Labels should be consistent with the Emplatado report for validation
     *
     * TEST SCENARIO:
     * ==============
     * - 1 ReportGrouper "TEST DISCRIMINADO GROUPER"
     * - 1 Company/Branch associated with the grouper
     * - 3 HORECA products, each using the SAME ingredient (PARMESANO) but different quantities:
     *   - Product A: "LASAÑA BOLOÑESA" - 30gr parmesano per portion, ordered 1 portion
     *   - Product B: "PASTA CARBONARA" - 20gr parmesano per portion, ordered 1 portion
     *   - Product C: "ENSALADA CESAR" - 10gr parmesano per portion, ordered 1 portion
     * - Expected weights in labels: [30, 20, 10] (discriminated by product)
     * - CURRENT BUG: weights will be [60] (consolidated)
     */
    public function test_horeca_labels_show_weights_discriminated_by_product_not_consolidated(): void
    {
        // 1. CREATE PRODUCTION AREA
        $areaEmplatado = ProductionArea::firstOrCreate(
            ['name' => 'EMPLATADO'],
            ['description' => 'Área de emplatado', 'active' => true]
        );

        // 2. CREATE CATEGORY
        $category = Category::create([
            'name' => 'HORECA DISCRIMINATED TEST',
            'code' => 'HDT',
            'description' => 'Category for discriminated weights test',
        ]);

        // 3. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'Lista Discriminated Test',
            'description' => 'Price list for discriminated weights test',
        ]);

        // 4. CREATE 3 HORECA PRODUCTS - ALL USING THE SAME INGREDIENT (PARMESANO)
        // BUT WITH DIFFERENT QUANTITIES PER PORTION

        // Product A: LASAÑA BOLOÑESA - 30gr parmesano per portion
        $productA = Product::create([
            'name' => 'TEST HORECA LASAÑA BOLOÑESA',
            'code' => 'T-LASANA-DISC',
            'description' => 'Test HORECA lasaña for discriminated test',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
        $productA->productionAreas()->attach($areaEmplatado->id);

        $platedDishA = PlatedDish::create([
            'product_id' => $productA->id,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishA->id,
            'ingredient_name' => 'MZC - QUESO PARMESANO RALLADO TEST',
            'quantity' => 30, // 30 GR per portion
            'measure_unit' => 'GR',
            'max_quantity_horeca' => 1000,
            'shelf_life' => 5,
            'order_index' => 1,
            'is_optional' => false,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productA->id,
            'unit_price' => 5000,
        ]);

        // Product B: PASTA CARBONARA - 20gr parmesano per portion
        $productB = Product::create([
            'name' => 'TEST HORECA PASTA CARBONARA',
            'code' => 'T-CARBONARA-DISC',
            'description' => 'Test HORECA pasta for discriminated test',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
        $productB->productionAreas()->attach($areaEmplatado->id);

        $platedDishB = PlatedDish::create([
            'product_id' => $productB->id,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishB->id,
            'ingredient_name' => 'MZC - QUESO PARMESANO RALLADO TEST',
            'quantity' => 20, // 20 GR per portion
            'measure_unit' => 'GR',
            'max_quantity_horeca' => 1000,
            'shelf_life' => 5,
            'order_index' => 1,
            'is_optional' => false,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productB->id,
            'unit_price' => 4500,
        ]);

        // Product C: ENSALADA CESAR - 10gr parmesano per portion
        $productC = Product::create([
            'name' => 'TEST HORECA ENSALADA CESAR',
            'code' => 'T-CESAR-DISC',
            'description' => 'Test HORECA ensalada for discriminated test',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
        $productC->productionAreas()->attach($areaEmplatado->id);

        $platedDishC = PlatedDish::create([
            'product_id' => $productC->id,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDishC->id,
            'ingredient_name' => 'MZC - QUESO PARMESANO RALLADO TEST',
            'quantity' => 10, // 10 GR per portion
            'measure_unit' => 'GR',
            'max_quantity_horeca' => 1000,
            'shelf_life' => 5,
            'order_index' => 1,
            'is_optional' => false,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productC->id,
            'unit_price' => 3500,
        ]);

        // 5. CREATE REPORT GROUPER
        $reportConfig = \App\Models\ReportConfiguration::firstOrCreate(
            ['name' => 'TEST DISCRIMINATED CONFIG'],
            ['description' => 'Test configuration for discriminated weights test', 'is_active' => true]
        );

        $grouper = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'TEST DISCRIMINADO GROUPER',
            'code' => 'TDG',
            'display_order' => 99,
            'is_active' => true,
        ]);

        // 6. CREATE COMPANY AND BRANCH
        $company = Company::create([
            'name' => 'TEST DISCRIMINATED COMPANY S.A.',
            'email' => 'discriminated@test.com',
            'tax_id' => '99999999-D',
            'company_code' => 'T-DISC',
            'fantasy_name' => 'Test Discriminated',
            'price_list_id' => $priceList->id,
        ]);

        // Associate company with grouper
        $grouper->companies()->attach($company->id);

        $branch = Branch::create([
            'company_id' => $company->id,
            'fantasy_name' => 'BRANCH DISCRIMINATED',
            'branch_code' => 'T-DISC-01',
            'address' => 'Address Discriminated',
            'min_price_order' => 0,
        ]);

        $user = User::create([
            'name' => 'User Discriminated',
            'nickname' => 'USER.DISC',
            'email' => 'user-disc@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        // 7. CREATE ORDER WITH ALL 3 PRODUCTS (1 portion each)
        $order = Order::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-12',
            'date' => '2025-12-12',
            'order_number' => 'ORD-DISC-001',
            'total' => 13000,
            'total_with_tax' => 15470,
            'tax_amount' => 2470,
            'grand_total' => 15470,
            'dispatch_cost' => 0,
        ]);

        // Order line for Product A (1 portion = 30gr parmesano)
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productA->id,
            'quantity' => 1,
            'unit_price' => 5000,
            'subtotal' => 5000,
        ]);

        // Order line for Product B (1 portion = 20gr parmesano)
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productB->id,
            'quantity' => 1,
            'unit_price' => 4500,
            'subtotal' => 4500,
        ]);

        // Order line for Product C (1 portion = 10gr parmesano)
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productC->id,
            'quantity' => 1,
            'unit_price' => 3500,
            'subtotal' => 3500,
        ]);

        // 8. CREATE ADVANCE ORDER
        $repository = new OrderRepository();
        $advanceOrder = $repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-12-12 08:00:00',
            [$areaEmplatado->id]
        );

        // 9. CALL HorecaLabelDataRepository
        $horecaLabelRepository = app(\App\Contracts\HorecaLabelDataRepositoryInterface::class);
        $labelData = $horecaLabelRepository->getHorecaLabelDataByAdvanceOrder($advanceOrder->id);

        // ========== ASSERTIONS ==========

        // Filter to only PARMESANO ingredient
        $parmesanoData = $labelData->filter(function ($item) {
            return str_contains($item['ingredient_name'], 'PARMESANO');
        });

        // ASSERTION 1: Should have 3 separate entries for PARMESANO (one per product)
        // CURRENT BUG: Will have 1 consolidated entry with weights [60]
        $this->assertCount(
            3,
            $parmesanoData,
            'Should have 3 separate label entries for PARMESANO (one per product). ' .
            'CURRENT BUG: Labels are consolidated into ' . $parmesanoData->count() . ' entries. ' .
            'Weights found: ' . json_encode($parmesanoData->pluck('weights')->toArray())
        );

        // ASSERTION 2: Each entry should have discriminated weight
        $weights = $parmesanoData->pluck('weights')->flatten()->sort()->values()->toArray();
        $expectedWeights = [10, 20, 30]; // Discriminated by product

        $this->assertEquals(
            $expectedWeights,
            $weights,
            'Weights should be discriminated by product [10, 20, 30]. ' .
            'CURRENT BUG: Weights are consolidated [60]. ' .
            'Found: ' . json_encode($weights)
        );

        // ASSERTION 3: Each entry should have product name for identification
        $this->assertTrue(
            $parmesanoData->every(function ($item) {
                return isset($item['product_name']) && !empty($item['product_name']);
            }),
            'Each label entry should have product_name field for identification. ' .
            'This allows production staff to know which dish the ingredient is for.'
        );

        // ASSERTION 4: Total quantity across all entries should still be 60gr
        $totalQuantity = $parmesanoData->sum('total_quantity_needed');
        $this->assertEquals(
            60,
            $totalQuantity,
            'Total quantity across all entries should be 60gr (30+20+10). ' .
            'Found: ' . $totalQuantity
        );

        // ========== EXPANDED LABELS ASSERTIONS ==========

        $service = app(\App\Services\Labels\HorecaLabelService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('expandLabelsWithWeights');
        $method->setAccessible(true);

        $expandedLabels = $method->invoke($service, $parmesanoData);

        // ASSERTION 5: Expanded labels should have 3 labels with different weights
        $expandedWeights = $expandedLabels->pluck('net_weight')->sort()->values()->toArray();

        $this->assertEquals(
            $expectedWeights,
            $expandedWeights,
            'Expanded labels should have weights [10, 20, 30] (one per product). ' .
            'Found: ' . json_encode($expandedWeights)
        );

        // ASSERTION 6: Each expanded label should have product_name for printing
        $this->assertTrue(
            $expandedLabels->every(function ($item) {
                return isset($item['product_name']) && !empty($item['product_name']);
            }),
            'Each expanded label should have product_name field for the label printout.'
        );

        // Cleanup
        $grouper->companies()->detach();
        $grouper->delete();
    }

    /**
     * TDD Test: Validate that HORECA labels are sorted by grouper, then by ingredient name.
     *
     * EXPECTED ORDER:
     * ===============
     * 1. First by grouper_name (alphabetically)
     * 2. Then by ingredient_name (alphabetically within each grouper)
     *
     * This ensures that:
     * - All labels for "GROUPER A" come before "GROUPER B"
     * - Within "GROUPER A", all "INGREDIENTE 1" labels come before "INGREDIENTE 2"
     *
     * TEST SCENARIO:
     * ==============
     * - 2 Groupers: "AAA GROUPER" and "ZZZ GROUPER"
     * - 2 Products per grouper, each with 2 ingredients:
     *   - Product 1: "ZZZ - INGREDIENTE Z" and "AAA - INGREDIENTE A"
     *   - Product 2: "MMM - INGREDIENTE M" (shared ingredient for sorting test)
     *
     * EXPECTED RESULT ORDER:
     * - AAA GROUPER + AAA - INGREDIENTE A (Product 1)
     * - AAA GROUPER + MMM - INGREDIENTE M (Product 2)
     * - AAA GROUPER + ZZZ - INGREDIENTE Z (Product 1)
     * - ZZZ GROUPER + AAA - INGREDIENTE A (Product 1)
     * - ZZZ GROUPER + MMM - INGREDIENTE M (Product 2)
     * - ZZZ GROUPER + ZZZ - INGREDIENTE Z (Product 1)
     */
    public function test_horeca_labels_are_sorted_by_grouper_then_by_ingredient(): void
    {
        // 1. CREATE PRODUCTION AREA
        $areaEmplatado = ProductionArea::firstOrCreate(
            ['name' => 'EMPLATADO'],
            ['description' => 'Área de emplatado', 'active' => true]
        );

        // 2. CREATE CATEGORY
        $category = Category::create([
            'name' => 'HORECA SORTING TEST',
            'code' => 'HST',
            'description' => 'Category for sorting test',
        ]);

        // 3. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'Lista Sorting Test',
            'description' => 'Price list for sorting test',
        ]);

        // 4. CREATE REPORT CONFIGURATION
        $reportConfig = \App\Models\ReportConfiguration::firstOrCreate(
            ['name' => 'TEST SORTING CONFIG'],
            ['description' => 'Test configuration for sorting test', 'is_active' => true]
        );

        // 5. CREATE 2 GROUPERS (alphabetically: AAA first, ZZZ last)
        $grouperAAA = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'AAA GROUPER TEST',
            'code' => 'AAAT',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $grouperZZZ = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'ZZZ GROUPER TEST',
            'code' => 'ZZZT',
            'display_order' => 2,
            'is_active' => true,
        ]);

        // 6. CREATE 2 COMPANIES (one per grouper)
        $companyAAA = Company::create([
            'name' => 'AAA SORTING COMPANY S.A.',
            'email' => 'aaa-sorting@test.com',
            'tax_id' => '11111111-A',
            'company_code' => 'T-AAA',
            'fantasy_name' => 'AAA Sorting',
            'price_list_id' => $priceList->id,
        ]);
        $grouperAAA->companies()->attach($companyAAA->id);

        $companyZZZ = Company::create([
            'name' => 'ZZZ SORTING COMPANY S.A.',
            'email' => 'zzz-sorting@test.com',
            'tax_id' => '99999999-Z',
            'company_code' => 'T-ZZZ',
            'fantasy_name' => 'ZZZ Sorting',
            'price_list_id' => $priceList->id,
        ]);
        $grouperZZZ->companies()->attach($companyZZZ->id);

        // 7. CREATE BRANCHES
        $branchAAA = Branch::create([
            'company_id' => $companyAAA->id,
            'fantasy_name' => 'BRANCH AAA',
            'branch_code' => 'T-AAA-01',
            'address' => 'Address AAA',
            'min_price_order' => 0,
        ]);

        $branchZZZ = Branch::create([
            'company_id' => $companyZZZ->id,
            'fantasy_name' => 'BRANCH ZZZ',
            'branch_code' => 'T-ZZZ-01',
            'address' => 'Address ZZZ',
            'min_price_order' => 0,
        ]);

        // 8. CREATE USERS
        $userAAA = User::create([
            'name' => 'User AAA',
            'nickname' => 'USER.AAA',
            'email' => 'user-aaa-sort@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyAAA->id,
            'branch_id' => $branchAAA->id,
        ]);

        $userZZZ = User::create([
            'name' => 'User ZZZ',
            'nickname' => 'USER.ZZZ',
            'email' => 'user-zzz-sort@test.com',
            'password' => bcrypt('password'),
            'company_id' => $companyZZZ->id,
            'branch_id' => $branchZZZ->id,
        ]);

        // 9. CREATE PRODUCT 1: Has 2 ingredients (ZZZ and AAA - intentionally reverse order)
        $product1 = Product::create([
            'name' => 'TEST SORTING PRODUCT 1',
            'code' => 'T-SORT-P1',
            'description' => 'Test product 1 for sorting',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
        $product1->productionAreas()->attach($areaEmplatado->id);

        $platedDish1 = PlatedDish::create([
            'product_id' => $product1->id,
            'is_horeca' => true,
        ]);

        // Ingredient ZZZ (intentionally first to test sorting)
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish1->id,
            'ingredient_name' => 'ZZZ - INGREDIENTE Z TEST',
            'quantity' => 100,
            'measure_unit' => 'GR',
            'max_quantity_horeca' => 1000,
            'shelf_life' => 5,
            'order_index' => 1,
            'is_optional' => false,
        ]);

        // Ingredient AAA (intentionally second to test sorting)
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish1->id,
            'ingredient_name' => 'AAA - INGREDIENTE A TEST',
            'quantity' => 50,
            'measure_unit' => 'GR',
            'max_quantity_horeca' => 1000,
            'shelf_life' => 5,
            'order_index' => 2,
            'is_optional' => false,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1->id,
            'unit_price' => 5000,
        ]);

        // 10. CREATE PRODUCT 2: Has 1 ingredient (MMM - in the middle alphabetically)
        $product2 = Product::create([
            'name' => 'TEST SORTING PRODUCT 2',
            'code' => 'T-SORT-P2',
            'description' => 'Test product 2 for sorting',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
        $product2->productionAreas()->attach($areaEmplatado->id);

        $platedDish2 = PlatedDish::create([
            'product_id' => $product2->id,
            'is_horeca' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish2->id,
            'ingredient_name' => 'MMM - INGREDIENTE M TEST',
            'quantity' => 75,
            'measure_unit' => 'GR',
            'max_quantity_horeca' => 1000,
            'shelf_life' => 5,
            'order_index' => 1,
            'is_optional' => false,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product2->id,
            'unit_price' => 4000,
        ]);

        // 11. CREATE ORDERS (one per company, both products)
        $orderAAA = Order::create([
            'user_id' => $userAAA->id,
            'branch_id' => $branchAAA->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-12',
            'date' => '2025-12-12',
            'order_number' => 'ORD-SORT-AAA',
            'total' => 9000,
            'total_with_tax' => 10710,
            'tax_amount' => 1710,
            'grand_total' => 10710,
            'dispatch_cost' => 0,
        ]);

        OrderLine::create([
            'order_id' => $orderAAA->id,
            'product_id' => $product1->id,
            'quantity' => 1,
            'unit_price' => 5000,
            'subtotal' => 5000,
        ]);

        OrderLine::create([
            'order_id' => $orderAAA->id,
            'product_id' => $product2->id,
            'quantity' => 1,
            'unit_price' => 4000,
            'subtotal' => 4000,
        ]);

        $orderZZZ = Order::create([
            'user_id' => $userZZZ->id,
            'branch_id' => $branchZZZ->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => '2025-12-12',
            'date' => '2025-12-12',
            'order_number' => 'ORD-SORT-ZZZ',
            'total' => 9000,
            'total_with_tax' => 10710,
            'tax_amount' => 1710,
            'grand_total' => 10710,
            'dispatch_cost' => 0,
        ]);

        OrderLine::create([
            'order_id' => $orderZZZ->id,
            'product_id' => $product1->id,
            'quantity' => 1,
            'unit_price' => 5000,
            'subtotal' => 5000,
        ]);

        OrderLine::create([
            'order_id' => $orderZZZ->id,
            'product_id' => $product2->id,
            'quantity' => 1,
            'unit_price' => 4000,
            'subtotal' => 4000,
        ]);

        // 12. CREATE ADVANCE ORDER
        $repository = new OrderRepository();
        $advanceOrder = $repository->createAdvanceOrderFromOrders(
            [$orderAAA->id, $orderZZZ->id],
            '2025-12-12 08:00:00',
            [$areaEmplatado->id]
        );

        // 13. CALL HorecaLabelDataRepository
        $horecaLabelRepository = app(\App\Contracts\HorecaLabelDataRepositoryInterface::class);
        $labelData = $horecaLabelRepository->getHorecaLabelDataByAdvanceOrder($advanceOrder->id);

        // ========== SORTING ASSERTIONS ==========

        // Should have 6 entries: 2 groupers × 3 ingredients (2 from P1 + 1 from P2)
        $this->assertCount(
            6,
            $labelData,
            'Should have 6 label entries (2 groupers × 3 ingredients). Found: ' . $labelData->count()
        );

        // Extract grouper+ingredient pairs in order
        $actualOrder = $labelData->map(function ($item) {
            return [
                'grouper' => $item['grouper_name'],
                'ingredient' => $item['ingredient_name'],
            ];
        })->values()->toArray();

        // EXPECTED ORDER:
        // 1. AAA GROUPER + AAA - INGREDIENTE A
        // 2. AAA GROUPER + MMM - INGREDIENTE M
        // 3. AAA GROUPER + ZZZ - INGREDIENTE Z
        // 4. ZZZ GROUPER + AAA - INGREDIENTE A
        // 5. ZZZ GROUPER + MMM - INGREDIENTE M
        // 6. ZZZ GROUPER + ZZZ - INGREDIENTE Z
        $expectedOrder = [
            ['grouper' => 'AAA GROUPER TEST', 'ingredient' => 'AAA - INGREDIENTE A TEST'],
            ['grouper' => 'AAA GROUPER TEST', 'ingredient' => 'MMM - INGREDIENTE M TEST'],
            ['grouper' => 'AAA GROUPER TEST', 'ingredient' => 'ZZZ - INGREDIENTE Z TEST'],
            ['grouper' => 'ZZZ GROUPER TEST', 'ingredient' => 'AAA - INGREDIENTE A TEST'],
            ['grouper' => 'ZZZ GROUPER TEST', 'ingredient' => 'MMM - INGREDIENTE M TEST'],
            ['grouper' => 'ZZZ GROUPER TEST', 'ingredient' => 'ZZZ - INGREDIENTE Z TEST'],
        ];

        $this->assertEquals(
            $expectedOrder,
            $actualOrder,
            "Labels should be sorted by grouper, then by ingredient name.\n" .
            "Expected: " . json_encode($expectedOrder, JSON_PRETTY_PRINT) . "\n" .
            "Actual: " . json_encode($actualOrder, JSON_PRETTY_PRINT)
        );

        // ADDITIONAL: Verify that within each grouper, ingredients are alphabetically sorted
        $grouperAAALabels = $labelData->filter(fn($item) => $item['grouper_name'] === 'AAA GROUPER TEST');
        $grouperZZZLabels = $labelData->filter(fn($item) => $item['grouper_name'] === 'ZZZ GROUPER TEST');

        $this->assertEquals(
            ['AAA - INGREDIENTE A TEST', 'MMM - INGREDIENTE M TEST', 'ZZZ - INGREDIENTE Z TEST'],
            $grouperAAALabels->pluck('ingredient_name')->values()->toArray(),
            'Within AAA GROUPER, ingredients should be sorted alphabetically'
        );

        $this->assertEquals(
            ['AAA - INGREDIENTE A TEST', 'MMM - INGREDIENTE M TEST', 'ZZZ - INGREDIENTE Z TEST'],
            $grouperZZZLabels->pluck('ingredient_name')->values()->toArray(),
            'Within ZZZ GROUPER, ingredients should be sorted alphabetically'
        );

        // Cleanup
        $grouperAAA->companies()->detach();
        $grouperAAA->delete();
        $grouperZZZ->companies()->detach();
        $grouperZZZ->delete();
    }
}
