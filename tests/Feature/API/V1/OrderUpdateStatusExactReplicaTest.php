<?php

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Menu;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Product;
use App\Models\PriceListLine;
use App\Models\Subcategory;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Role;
use App\Models\Permission;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * This test replicates EXACTLY the structure of production data
 * to validate that WORKING scenarios continue to work as expected.
 */
class OrderUpdateStatusExactReplicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_update_fails_when_missing_entrada_exactly_like_real_scenario(): void
    {
        // ===  CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]); // ID will be 1
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]); // ID will be 1

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => false,
        ]);

        // === CREATE COMPANY ===
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => '',
            'address' => '-',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => false,
        ]);

        // === CREATE USER ===
        $user = User::create([
            'name' => 'Test User Master',
            'nickname' => 'TEST.USER.MASTER',
            'email' => null,
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === CREATE SUBCATEGORIES ===
        $entradaSubcat = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $calienteSubcat = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $friaSubcat = Subcategory::firstOrCreate(['name' => 'FRIA']);
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);

        // === CREATE MENU 204 ===
        $menu = Menu::create([
            'title' => 'CONVENIO INDV. HORECA SIN FDS 14/10/25',
            'publication_date' => '2025-10-14',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // === CREATE CATEGORIES ===

        // Category 109: SOPAS Y CREMAS VARIABLES HORECA (ENTRADA)
        $category109 = Category::create([
            'name' => 'SOPAS Y CREMAS VARIABLES HORECA',
            'description' => '-',
            'active' => true,
        ]);
        $category109->subcategories()->attach([$entradaSubcat->id, $calienteSubcat->id]);

        // Category 110: MINI ENSALADAS DE ACOMPAÑAMIENTO HORECA (ENTRADA)
        $category110 = Category::create([
            'name' => 'MINI ENSALADAS DE ACOMPAÑAMIENTO HORECA',
            'description' => '-',
            'active' => true,
        ]);
        $category110->subcategories()->attach([$entradaSubcat->id, $friaSubcat->id]);

        // Category 77: PLATOS FIJOS PARA CALENTAR HORECA (PLATO DE FONDO)
        $category77 = Category::create([
            'name' => 'PLATOS FIJOS PARA CALENTAR HORECA',
            'description' => null,
            'active' => true,
        ]);
        $category77->subcategories()->attach($platoFondoSubcat->id);

        // === CREATE PRODUCTS ===

        // Product 1067: ENTRADA - SOPAS
        $product1067 = Product::create([
            'name' => 'ACM - HORECA CONSOME DE POLLO INDIVIDUAL',
            'code' => 'ACM00000008',
            'description' => 'No description',
            'category_id' => $category109->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1067->id,
            'unit_price' => 20000.00,
            'active' => true,
        ]);

        // Product 1086: ENTRADA - ENSALADAS
        $product1086 = Product::create([
            'name' => 'ACM - HORECA MINI ENSALADA CHOCLO CIBOULETTE',
            'code' => 'ACM00000030',
            'description' => 'No description',
            'category_id' => $category110->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1086->id,
            'unit_price' => 20000.00,
            'active' => true,
        ]);

        // Product 1819: PLATO DE FONDO
        $product1819 = Product::create([
            'name' => 'PCFH - HORECA LENTEJAS GOURMET AL PARMESANO',
            'code' => 'PCFH00000003',
            'description' => 'No description',
            'category_id' => $category77->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1819->id,
            'unit_price' => 470000.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENUS ===

        // CategoryMenu 5212: SOPAS (ENTRADA)
        $categoryMenu5212 = CategoryMenu::create([
            'category_id' => $category109->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 100,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenu5212->products()->attach($product1067->id);

        // CategoryMenu 5217: ENSALADAS (ENTRADA)
        $categoryMenu5217 = CategoryMenu::create([
            'category_id' => $category110->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 180,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenu5217->products()->attach($product1086->id);

        // CategoryMenu for PLATO DE FONDO
        $categoryMenuPlatoFondo = CategoryMenu::create([
            'category_id' => $category77->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 50,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuPlatoFondo->products()->attach($product1819->id);

        // === CREATE ORDER (only with PLATO DE FONDO, missing ENTRADA) ===
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => null,
            'dispatch_date' => Carbon::parse('2025-10-14'),
            'status' => OrderStatus::PENDING->value,
            'total' => 470000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product1819->id,
            'quantity' => 1,
            'unit_price' => 470000,
            'total' => 470000,
        ]);

        // === TEST: Try to update order status ===
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-14", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // === ASSERTIONS ===
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    '🍽️ Tu menú necesita algunos elementos para estar completo: Entrada.',
                ],
            ],
        ]);
    }

    /**
     * Test that adding a second product from the same PLATO DE FONDO category
     * fails with the appropriate validation message.
     *
     * This replicates the scenario where user already has product 1819 in order
     * and tries to add product 1817 (both are PLATO DE FONDO from same category 77)
     */
    public function test_cannot_add_multiple_products_from_same_plato_fondo_category(): void
    {
        // ===  CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => false,
        ]);

        // === CREATE COMPANY ===
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => '',
            'address' => '-',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => false,
        ]);

        // === CREATE USER ===
        $user = User::create([
            'name' => 'Test User Master',
            'nickname' => 'TEST.USER.MASTER',
            'email' => null,
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === CREATE SUBCATEGORIES ===
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONVENIO INDV. HORECA SIN FDS 14/10/25',
            'publication_date' => '2025-10-14',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // === CREATE CATEGORY 77: PLATOS FIJOS PARA CALENTAR HORECA ===
        $category77 = Category::create([
            'name' => 'PLATOS FIJOS PARA CALENTAR HORECA',
            'description' => null,
            'active' => true,
        ]);
        $category77->subcategories()->attach($platoFondoSubcat->id);

        // === CREATE PRODUCTS ===

        // Product 1819: LENTEJAS (already in order)
        $product1819 = Product::create([
            'name' => 'PCFH - HORECA LENTEJAS GOURMET AL PARMESANO',
            'code' => 'PCFH00000003',
            'description' => 'No description',
            'category_id' => $category77->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1819->id,
            'unit_price' => 470000.00,
            'active' => true,
        ]);

        // Product 1817: LASANA (trying to add)
        $product1817 = Product::create([
            'name' => 'PCFH - HORECA LASANA FLORENTINA DE POLLO',
            'code' => 'PCFH00000001',
            'description' => 'No description',
            'category_id' => $category77->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1817->id,
            'unit_price' => 470000.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENU ===
        $categoryMenuPlatoFondo = CategoryMenu::create([
            'category_id' => $category77->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 50,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuPlatoFondo->products()->attach([$product1819->id, $product1817->id]);

        // === CREATE ORDER WITH PRODUCT 1819 ===
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => null,
            'dispatch_date' => Carbon::parse('2025-10-14'),
            'status' => OrderStatus::PENDING->value,
            'total' => 470000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product1819->id,
            'quantity' => 1,
            'unit_price' => 470000,
            'total' => 470000,
        ]);

        // === TEST: Try to add second PLATO DE FONDO product ===
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/create-or-update-order/2025-10-14", [
            'order_lines' => [
                ['id' => $product1817->id, 'quantity' => 1, 'partially_scheduled' => false]
            ]
        ]);

        // === ASSERTIONS ===
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    "Solo puedes elegir un PLATO DE FONDO por pedido.\n\n"
                ],
            ],
        ]);
    }

    /**
     * Test that trying to combine incompatible subcategories fails with validation error.
     *
     * This replicates the scenario where:
     * - Order already has product 1255 with subcategories: PLATO DE FONDO + HIPOCALORICO
     * - User tries to add product 1086 with subcategories: ENTRADA + FRIA
     * - System should reject because FRIA cannot be combined with HIPOCALORICO
     */
    public function test_cannot_combine_incompatible_subcategories_fria_with_hipocalorico(): void
    {
        // ===  CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => false,
        ]);

        // === CREATE COMPANY ===
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => '',
            'address' => '-',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => false,
        ]);

        // === CREATE USER ===
        $user = User::create([
            'name' => 'Test User Master',
            'nickname' => 'TEST.USER.MASTER',
            'email' => null,
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === CREATE SUBCATEGORIES ===
        $entradaSubcat = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $friaSubcat = Subcategory::firstOrCreate(['name' => 'FRIA']);
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);
        $hipocaloricoSubcat = Subcategory::firstOrCreate(['name' => 'HIPOCALORICO']);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONVENIO INDV. HORECA SIN FDS 14/10/25',
            'publication_date' => '2025-10-14',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // === CREATE CATEGORIES ===

        // Category: ENSALADAS EXTRA PROTEINA (PLATO DE FONDO + HIPOCALORICO)
        $categoryEnsaladasExtra = Category::create([
            'name' => 'ENSALADAS EXTRA PROTEINA',
            'description' => null,
            'active' => true,
        ]);
        $categoryEnsaladasExtra->subcategories()->attach([$platoFondoSubcat->id, $hipocaloricoSubcat->id]);

        // Category: MINI ENSALADAS HORECA (ENTRADA + FRIA)
        $categoryMiniEnsaladas = Category::create([
            'name' => 'MINI ENSALADAS DE ACOMPAÑAMIENTO HORECA',
            'description' => '-',
            'active' => true,
        ]);
        $categoryMiniEnsaladas->subcategories()->attach([$entradaSubcat->id, $friaSubcat->id]);

        // === CREATE PRODUCTS ===

        // Product 1255: ENSALADA VEGANA (PLATO DE FONDO + HIPOCALORICO) - already in order
        $product1255 = Product::create([
            'name' => 'ENX - ENSALADA VEGANA CROQUETAS DE LENTEJAS EXTRA PROTEINA',
            'code' => 'ENX00000017',
            'description' => 'No description',
            'category_id' => $categoryEnsaladasExtra->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1255->id,
            'unit_price' => 470000.00,
            'active' => true,
        ]);

        // Product 1086: MINI ENSALADA (ENTRADA + FRIA) - trying to add
        $product1086 = Product::create([
            'name' => 'ACM - HORECA MINI ENSALADA CHOCLO CIBOULETTE',
            'code' => 'ACM00000030',
            'description' => 'No description',
            'category_id' => $categoryMiniEnsaladas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1086->id,
            'unit_price' => 20000.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENUS ===
        $categoryMenuExtra = CategoryMenu::create([
            'category_id' => $categoryEnsaladasExtra->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 50,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuExtra->products()->attach($product1255->id);

        $categoryMenuMini = CategoryMenu::create([
            'category_id' => $categoryMiniEnsaladas->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 180,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuMini->products()->attach($product1086->id);

        // === CREATE ORDER WITH PRODUCT 1255 (HIPOCALORICO) ===
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => null,
            'dispatch_date' => Carbon::parse('2025-10-14'),
            'status' => OrderStatus::PENDING->value,
            'total' => 470000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product1255->id,
            'quantity' => 1,
            'unit_price' => 470000,
            'total' => 470000,
        ]);

        // === TEST: Try to add product with FRIA subcategory ===
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/create-or-update-order/2025-10-14", [
            'order_lines' => [
                ['id' => $product1086->id, 'quantity' => 1, 'partially_scheduled' => false]
            ]
        ]);

        // === ASSERTIONS ===
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    "No puedes combinar las subcategorías: FRIA con HIPOCALORICO.\n\n"
                ],
            ],
        ]);
    }

    public function test_cannot_add_product_from_duplicate_plato_de_fondo_subcategory(): void
    {
        // ===  CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => false,
        ]);

        // === CREATE COMPANY ===
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => '',
            'address' => '-',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => false,
        ]);

        // === CREATE USER ===
        $user = User::create([
            'name' => 'Test User Master',
            'nickname' => 'TEST.USER.MASTER',
            'email' => null,
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === CREATE SUBCATEGORIES ===
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);
        $hipocaloricoSubcat = Subcategory::firstOrCreate(['name' => 'HIPOCALORICO']);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONVENIO INDV. HORECA SIN FDS 14/10/25',
            'publication_date' => '2025-10-14',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // === CREATE CATEGORY 84: ENSALADAS EXTRA PROTEINA (PLATO DE FONDO + HIPOCALORICO) ===
        $category84 = Category::create([
            'name' => 'ENSALADAS EXTRA PROTEINA',
            'description' => null,
            'active' => true,
        ]);
        $category84->subcategories()->attach([$platoFondoSubcat->id, $hipocaloricoSubcat->id]);

        // === CREATE CATEGORY 77: PLATOS FIJOS PARA CALENTAR HORECA (PLATO DE FONDO) ===
        $category77 = Category::create([
            'name' => 'PLATOS FIJOS PARA CALENTAR HORECA',
            'description' => null,
            'active' => true,
        ]);
        $category77->subcategories()->attach($platoFondoSubcat->id);

        // === CREATE PRODUCTS ===

        // Product 1255: ENX - ENSALADA VEGANA (already in order)
        $product1255 = Product::create([
            'name' => 'ENX - ENSALADA VEGANA CROQUETAS DE LENTEJAS EXTRA PROTEINA',
            'code' => 'ENX00000001',
            'description' => 'No description',
            'category_id' => $category84->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1255->id,
            'unit_price' => 5500.00,
            'active' => true,
        ]);

        // Product 1817: LASANA (trying to add)
        $product1817 = Product::create([
            'name' => 'PCFH - HORECA LASANA FLORENTINA DE POLLO',
            'code' => 'PCFH00000001',
            'description' => 'No description',
            'category_id' => $category77->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1817->id,
            'unit_price' => 5500.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENUS ===
        $categoryMenu84 = CategoryMenu::create([
            'category_id' => $category84->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 40,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenu84->products()->attach($product1255->id);

        $categoryMenu77 = CategoryMenu::create([
            'category_id' => $category77->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 50,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenu77->products()->attach($product1817->id);

        // === CREATE ORDER WITH PRODUCT 1255 ===
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => null,
            'dispatch_date' => Carbon::parse('2025-10-14'),
            'status' => OrderStatus::PENDING->value,
            'total' => 5500,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product1255->id,
            'quantity' => 1,
            'unit_price' => 5500,
            'total' => 5500,
        ]);

        // === TEST: Try to add product 1817 (also PLATO DE FONDO) ===
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/create-or-update-order/2025-10-14", [
            'order_lines' => [
                ['id' => $product1817->id, 'quantity' => 1, 'partially_scheduled' => false]
            ]
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    "Solo puedes elegir un PLATO DE FONDO por pedido.\n\n"
                ],
            ],
        ]);
    }

    public function test_cannot_order_product_without_required_preparation_days(): void
    {
        // ===  CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => false,
        ]);

        // === CREATE COMPANY ===
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => '',
            'address' => '-',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => false,
        ]);

        // === CREATE USER with allow_late_orders ===
        $user = User::create([
            'name' => 'Test User Master',
            'nickname' => 'TEST.USER.MASTER',
            'email' => null,
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
            'allow_late_orders' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === SET TEST TIME BEFORE CREATING MENU ===
        // Set current time to Monday 2025-10-13 at 12:00 (1 day before delivery)
        Carbon::setTestNow(Carbon::parse('2025-10-13 12:00:00'));

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONVENIO INDV. HORECA SIN FDS 14/10/25',
            'publication_date' => '2025-10-14',
            'max_order_date' => '2025-10-14 18:00:00',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // Associate menu with company
        $menu->companies()->attach($company->id);

        // === CREATE CATEGORY WITH PREPARATION DAYS ===
        $category = Category::create([
            'name' => 'PLATOS VARIABLES PARA CALENTAR HORECA',
            'description' => null,
            'active' => true,
        ]);

        // === CREATE CATEGORY LINE FOR TUESDAY WITH 4 DAYS PREPARATION ===
        $categoryLine = \App\Models\CategoryLine::create([
            'category_id' => $category->id,
            'weekday' => 'tuesday',
            'preparation_days' => 4,
            'maximum_order_time' => '2025-10-13 18:00:00',
            'active' => true,
        ]);

        // === CREATE PRODUCT ===
        $product = Product::create([
            'name' => 'PCH - HORECA BUDIN DE ATUN CON ARROZ ATOMATADO',
            'code' => 'PCH00000024',
            'description' => 'No description',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 5500.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENU ===
        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 50,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenu->products()->attach($product->id);

        // === TEST: Try to order product for tomorrow (2025-10-14 = Tuesday) when today is 2025-10-13 (Monday) ===
        // This should fail because tuesday requires 4 days in advance but we're only 1 day in advance
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/create-or-update-order/2025-10-14", [
            'order_lines' => [
                ['id' => $product->id, 'quantity' => 1, 'partially_scheduled' => false]
            ]
        ]);

        // === ASSERTIONS ===
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    "El producto 'Pch - horeca budin de atun con arroz atomatado' no puede ser pedido para este día. Debe ser pedido con 4 días de anticipación."
                ],
            ],
        ]);

        // Clean up test time
        Carbon::setTestNow();
    }

    public function test_cannot_add_multiple_products_from_same_category_without_subcategories(): void
    {
        // ===  CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => false,
        ]);

        // === CREATE COMPANY ===
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => '',
            'address' => '-',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => false,
        ]);

        // === CREATE USER ===
        $user = User::create([
            'name' => 'Test User Master',
            'nickname' => 'TEST.USER.MASTER',
            'email' => null,
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONVENIO INDV. HORECA SIN FDS 18/10/25',
            'publication_date' => '2025-10-18',
            'max_order_date' => '2025-10-18 18:00:00',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        $menu->companies()->attach($company->id);

        // === CREATE CATEGORY WITHOUT SUBCATEGORIES ===
        $category = Category::create([
            'name' => 'POSTRES',
            'description' => null,
            'active' => true,
        ]);

        // Note: No subcategories attached to this category

        // === CREATE PRODUCTS ===
        // Product 1038: Already in order
        $product1038 = Product::create([
            'name' => 'PTR - BARRA DE CEREAL',
            'code' => 'PTR00000001',
            'description' => 'No description',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1038->id,
            'unit_price' => 1500.00,
            'active' => true,
        ]);

        // Product 1039: Trying to add
        $product1039 = Product::create([
            'name' => 'PTR - FLAN 120 GR',
            'code' => 'PTR00000002',
            'description' => 'No description',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1039->id,
            'unit_price' => 1500.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENU ===
        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 50,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenu->products()->attach([$product1038->id, $product1039->id]);

        // === CREATE ORDER WITH PRODUCT 1038 ===
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => null,
            'dispatch_date' => Carbon::parse('2025-10-18'),
            'status' => OrderStatus::PENDING->value,
            'total' => 1500,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product1038->id,
            'quantity' => 1,
            'unit_price' => 1500,
            'total' => 1500,
        ]);

        // === TEST: Try to add product 1039 (same category without subcategories) ===
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/create-or-update-order/2025-10-18", [
            'order_lines' => [
                ['id' => $product1039->id, 'quantity' => 1, 'partially_scheduled' => false]
            ]
        ]);

        // === ASSERTIONS ===
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    "🚫 Solo puedes elegir un producto de POSTRES.\n\n"
                ],
            ],
        ]);
    }

    public function test_all_products_must_have_same_quantity(): void
    {
        // ===  CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => false,
        ]);

        // === CREATE COMPANY ===
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => '',
            'address' => '-',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => false,
        ]);

        // === CREATE USER ===
        $user = User::create([
            'name' => 'Test User Master',
            'nickname' => 'TEST.USER.MASTER',
            'email' => null,
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONVENIO INDV. HORECA SIN FDS 18/10/25',
            'publication_date' => '2025-10-18',
            'max_order_date' => '2025-10-18 18:00:00',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        $menu->companies()->attach($company->id);

        // === CREATE SUBCATEGORIES ===
        $entradaSubcat = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);

        // === CREATE CATEGORIES WITH SUBCATEGORIES ===
        $categoryEntrada = Category::create([
            'name' => 'ENTRADAS TEST',
            'description' => null,
            'active' => true,
        ]);
        $categoryEntrada->subcategories()->attach($entradaSubcat->id);

        $categoryPlatoFondo = Category::create([
            'name' => 'PLATOS FONDO TEST',
            'description' => null,
            'active' => true,
        ]);
        $categoryPlatoFondo->subcategories()->attach($platoFondoSubcat->id);

        // === CREATE PRODUCTS ===
        $productEntrada = Product::create([
            'name' => 'ENTRADA TEST',
            'code' => 'ENT00001',
            'description' => 'No description',
            'category_id' => $categoryEntrada->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productEntrada->id,
            'unit_price' => 2000.00,
            'active' => true,
        ]);

        $productPlatoFondo = Product::create([
            'name' => 'PLATO FONDO TEST',
            'code' => 'PLF00001',
            'description' => 'No description',
            'category_id' => $categoryPlatoFondo->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productPlatoFondo->id,
            'unit_price' => 3000.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENUS ===
        $categoryMenuEntrada = CategoryMenu::create([
            'category_id' => $categoryEntrada->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 10,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuEntrada->products()->attach($productEntrada->id);

        $categoryMenuPlatoFondo = CategoryMenu::create([
            'category_id' => $categoryPlatoFondo->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 20,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuPlatoFondo->products()->attach($productPlatoFondo->id);

        // === CREATE ORDER WITH DIFFERENT QUANTITIES ===
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => null,
            'dispatch_date' => Carbon::parse('2025-10-18'),
            'status' => OrderStatus::PENDING->value,
            'total' => 7000,
        ]);

        // Product with quantity 2
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productEntrada->id,
            'quantity' => 2,
            'unit_price' => 2000,
            'total' => 4000,
        ]);

        // Product with quantity 1 (different from previous)
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productPlatoFondo->id,
            'quantity' => 1,
            'unit_price' => 3000,
            'total' => 3000,
        ]);

        // === TEST: Try to update order status with different quantities ===
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-18", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // === ASSERTIONS ===
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    "Todos los productos en la orden deben tener la misma cantidad."
                ],
            ],
        ]);
    }

    public function test_order_must_meet_minimum_amount(): void
    {
        // ===  CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => false,
        ]);

        // === CREATE COMPANY ===
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // === CREATE BRANCH WITH MINIMUM ORDER AMOUNT ===
        $branch = Branch::create([
            'name' => '',
            'address' => '-',
            'company_id' => $company->id,
            'min_price_order' => 10000, // Minimum amount: $10,000
            'active' => false,
        ]);

        // === CREATE USER WITH validate_min_price = true ===
        $user = User::create([
            'name' => 'Test User Master',
            'nickname' => 'TEST.USER.MASTER',
            'email' => null,
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
            'validate_min_price' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONVENIO INDV. HORECA SIN FDS 18/10/25',
            'publication_date' => '2025-10-18',
            'max_order_date' => '2025-10-18 18:00:00',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        $menu->companies()->attach($company->id);

        // === CREATE CATEGORY ===
        $category = Category::create([
            'name' => 'PRODUCTOS TEST',
            'description' => null,
            'active' => true,
        ]);

        // === CREATE PRODUCT ===
        $product = Product::create([
            'name' => 'PRODUCTO ECONOMICO',
            'code' => 'PRD00001',
            'description' => 'No description',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 3000.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENU ===
        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'order' => null,
            'display_order' => 10,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenu->products()->attach($product->id);

        // === CREATE ORDER WITH AMOUNT BELOW MINIMUM ===
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => null,
            'dispatch_date' => Carbon::parse('2025-10-18'),
            'status' => OrderStatus::PENDING->value,
            'total' => 3000, // Below minimum of $10,000
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 3000,
            'total' => 3000,
        ]);

        // === TEST: Try to update order status with amount below minimum ===
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-18", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // === ASSERTIONS ===
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    "El monto del pedido mínimo es $100,00"
                ],
            ],
        ]);
    }

    public function test_menu_188_should_not_require_entrada_when_no_entrada_products_have_price(): void
    {
        // ===  CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => false,
        ]);

        // === CREATE COMPANY ===
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => '',
            'address' => '-',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => false,
        ]);

        // === CREATE USER ===
        $user = User::create([
            'name' => 'Test User Master',
            'nickname' => 'TEST.USER.MASTER',
            'email' => null,
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
            'validate_min_price' => false,
            'allow_late_orders' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONVENIO INDIVIDUAL NO HORECA 19/10/25',
            'publication_date' => '2025-10-19',
            'max_order_date' => '2025-10-17 18:00:00',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // No company association (general menu)

        // === CREATE SUBCATEGORIES ===
        $entradaSubcat = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $friaSubcat = Subcategory::firstOrCreate(['name' => 'FRIA']);
        $calienteSubcat = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);
        $hipocaloricoSubcat = Subcategory::firstOrCreate(['name' => 'HIPOCALORICO']);
        $panSubcat = Subcategory::firstOrCreate(['name' => 'PAN DE ACOMPAÑAMIENTO']);

        // === CREATE CATEGORIES ===

        // Category with ENTRADA but products WITHOUT price
        $categoryMiniEnsaladas = Category::create([
            'name' => 'MINI ENSALADAS DE ACOMPAÑAMIENTO',
            'description' => null,
            'active' => true,
        ]);
        $categoryMiniEnsaladas->subcategories()->attach([$entradaSubcat->id, $friaSubcat->id]);

        $categorySopasFijas = Category::create([
            'name' => 'SOPAS Y CREMAS FIJAS PARA CALENTAR',
            'description' => null,
            'active' => true,
        ]);
        $categorySopasFijas->subcategories()->attach([$entradaSubcat->id, $calienteSubcat->id]);

        // Category with PLATO DE FONDO (will have products WITH price)
        $categoryEnsaladasProtein = Category::create([
            'name' => 'ENSALADAS EXTRA PROTEINA',
            'description' => null,
            'active' => true,
        ]);
        $categoryEnsaladasProtein->subcategories()->attach([$platoFondoSubcat->id, $hipocaloricoSubcat->id]);

        // Categories without subcategories
        $categoryPostres = Category::create([
            'name' => 'POSTRES',
            'description' => null,
            'active' => true,
        ]);

        $categoryAcompañamientos = Category::create([
            'name' => 'ACOMPAÑAMIENTOS',
            'description' => null,
            'active' => true,
        ]);
        $categoryAcompañamientos->subcategories()->attach($panSubcat->id);

        $categoryBebestibles = Category::create([
            'name' => 'BEBESTIBLES',
            'description' => null,
            'active' => true,
        ]);

        // Create CategoryLines for sunday (dispatch date) for all categories
        \App\Models\CategoryLine::create([
            'category_id' => $categoryEnsaladasProtein->id,
            'weekday' => 'sunday',
            'preparation_days' => 0,
            'maximum_order_time' => '2025-10-19 18:00:00',
            'active' => true,
        ]);

        \App\Models\CategoryLine::create([
            'category_id' => $categoryPostres->id,
            'weekday' => 'sunday',
            'preparation_days' => 0,
            'maximum_order_time' => '2025-10-19 18:00:00',
            'active' => true,
        ]);

        \App\Models\CategoryLine::create([
            'category_id' => $categoryAcompañamientos->id,
            'weekday' => 'sunday',
            'preparation_days' => 0,
            'maximum_order_time' => '2025-10-19 18:00:00',
            'active' => true,
        ]);

        \App\Models\CategoryLine::create([
            'category_id' => $categoryBebestibles->id,
            'weekday' => 'sunday',
            'preparation_days' => 0,
            'maximum_order_time' => '2025-10-19 18:00:00',
            'active' => true,
        ]);

        // === CREATE PRODUCTS ===

        // ENTRADA products WITHOUT price (these ARE in the menu)
        $productMiniEnsalada = Product::create([
            'name' => 'ACM - MINI ENSALADA LECHUGA PRIMAVERA',
            'code' => 'ACM00001',
            'description' => 'No description',
            'category_id' => $categoryMiniEnsaladas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
        // NO PRICE LIST LINE for this product

        $productCremaZapallo = Product::create([
            'name' => 'ACM - CREMA DE ZAPALLO 300 GR',
            'code' => 'ACM00002',
            'description' => 'No description',
            'category_id' => $categorySopasFijas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
        // NO PRICE LIST LINE for this product

        // THE BUG: These ENTRADA products are NOT in the menu but HAVE prices
        // This causes the whereHas filter to include the ENTRADA categories
        // even though the specific products IN the menu have no prices
        $productSinMiniEnsalada = Product::create([
            'name' => 'ACM - SIN MINI ENSALADA',
            'code' => 'ACM00003',
            'description' => 'No description',
            'category_id' => $categoryMiniEnsaladas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productSinMiniEnsalada->id,
            'unit_price' => 0.00,
            'active' => true,
        ]);
        // This product is NOT attached to the menu, but it HAS a price

        $productSinSopa = Product::create([
            'name' => 'ACM - SIN SOPA O CREMA',
            'code' => 'ACM00004',
            'description' => 'No description',
            'category_id' => $categorySopasFijas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productSinSopa->id,
            'unit_price' => 0.00,
            'active' => true,
        ]);
        // This product is NOT attached to the menu, but it HAS a price

        // PLATO DE FONDO product WITH price
        $productEnsaladaCesar = Product::create([
            'name' => 'ENX - ENSALADA CESAR CON POLLO EXTRA PROTEINA',
            'code' => 'ENX00001',
            'description' => 'No description',
            'category_id' => $categoryEnsaladasProtein->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productEnsaladaCesar->id,
            'unit_price' => 470000.00,
            'active' => true,
        ]);

        // Products without subcategories WITH price
        $productPostre = Product::create([
            'name' => 'PTR - BARRA DE CEREAL',
            'code' => 'PTR00001',
            'description' => 'No description',
            'category_id' => $categoryPostres->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productPostre->id,
            'unit_price' => 25000.00,
            'active' => true,
        ]);

        $productAcompanamiento = Product::create([
            'name' => 'EXT - AMASADO DELICIUS MINI',
            'code' => 'EXT00001',
            'description' => 'No description',
            'category_id' => $categoryAcompañamientos->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productAcompanamiento->id,
            'unit_price' => 15000.00,
            'active' => true,
        ]);

        $productBebestible = Product::create([
            'name' => 'BEB - AGUA MINERAL C/GAS 500 ML',
            'code' => 'BEB00001',
            'description' => 'No description',
            'category_id' => $categoryBebestibles->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productBebestible->id,
            'unit_price' => 45000.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENUS ===
        $cmMiniEnsaladas = CategoryMenu::create([
            'category_id' => $categoryMiniEnsaladas->id,
            'menu_id' => $menu->id,
            'display_order' => 5,
            'mandatory_category' => true,
            'is_active' => true,
        ]);
        $cmMiniEnsaladas->products()->attach($productMiniEnsalada->id);

        $cmSopasFijas = CategoryMenu::create([
            'category_id' => $categorySopasFijas->id,
            'menu_id' => $menu->id,
            'display_order' => 15,
            'mandatory_category' => true,
            'is_active' => true,
        ]);
        $cmSopasFijas->products()->attach($productCremaZapallo->id);

        $cmEnsaladasProtein = CategoryMenu::create([
            'category_id' => $categoryEnsaladasProtein->id,
            'menu_id' => $menu->id,
            'display_order' => 65,
            'mandatory_category' => true,
            'is_active' => true,
        ]);
        $cmEnsaladasProtein->products()->attach($productEnsaladaCesar->id);

        $cmAcompañamientos = CategoryMenu::create([
            'category_id' => $categoryAcompañamientos->id,
            'menu_id' => $menu->id,
            'display_order' => 200,
            'mandatory_category' => true,
            'is_active' => true,
        ]);
        $cmAcompañamientos->products()->attach($productAcompanamiento->id);

        $cmPostres = CategoryMenu::create([
            'category_id' => $categoryPostres->id,
            'menu_id' => $menu->id,
            'display_order' => 210,
            'mandatory_category' => true,
            'is_active' => true,
        ]);
        $cmPostres->products()->attach($productPostre->id);

        $cmBebestibles = CategoryMenu::create([
            'category_id' => $categoryBebestibles->id,
            'menu_id' => $menu->id,
            'display_order' => 220,
            'mandatory_category' => true,
            'is_active' => true,
        ]);
        $cmBebestibles->products()->attach($productBebestible->id);

        // === CREATE ORDER (like order 108) ===
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => null,
            'dispatch_date' => Carbon::parse('2025-10-19'),
            'status' => OrderStatus::PENDING->value,
            'total' => 555000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productEnsaladaCesar->id,
            'quantity' => 1,
            'unit_price' => 470000,
            'total' => 470000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productPostre->id,
            'quantity' => 1,
            'unit_price' => 25000,
            'total' => 25000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productAcompanamiento->id,
            'quantity' => 1,
            'unit_price' => 15000,
            'total' => 15000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productBebestible->id,
            'quantity' => 1,
            'unit_price' => 45000,
            'total' => 45000,
        ]);

        // === TEST: Try to update order status ===
        // BUG REPLICATION: This test replicates the exact bug from menu 188, order 108
        //
        // THE BUG:
        // - ENTRADA categories have products in the menu (1106, 1057) with NO prices
        // - ENTRADA categories also have products NOT in menu (1947, 1948) WITH prices
        // - The whereHas filter in MenuCompositionValidation checks at CATEGORY level
        // - So it includes ENTRADA categories because the category HAS products with prices
        // - Even though the specific products IN THE MENU have no prices
        // - The validation then requires ENTRADA in the order, causing 422 error
        // - But user can't select ENTRADA products because they have no prices!
        //
        // EXPECTED BEHAVIOR:
        // - Should return 200 because the products that ARE in the menu have no prices
        // - The filter should check products at the MENU-PIVOT level, not CATEGORY level
        //
        // THIS TEST WILL FAIL until the bug is fixed in MenuCompositionValidation

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-19", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // === ASSERTIONS ===
        // BUG FIX COMPLETE - This test now passes!
        // The system correctly returns 200 because ENTRADA products have no prices
        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Order status updated successfully',
        ]);
    }
}
