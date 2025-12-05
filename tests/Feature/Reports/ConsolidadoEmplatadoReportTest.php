<?php

namespace Tests\Feature\Reports;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderStatus;
use App\Models\AdvanceOrder;
use App\Repositories\OrderRepository;
use App\Repositories\ConsolidadoEmplatadoRepository;
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
 */
class ConsolidadoEmplatadoReportTest extends TestCase
{
    use RefreshDatabase;

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
        $consolidadoRepository = new ConsolidadoEmplatadoRepository();
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

        $clientes1 = collect($ingredient1['clientes'])->keyBy('branch_name');

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
        $clientes2 = collect($ingredient2['clientes'])->keyBy('branch_name');

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
    }
}
