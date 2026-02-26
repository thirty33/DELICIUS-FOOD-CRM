<?php

namespace Tests\Feature\Commands;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Jobs\OrderCategoryMenuProductsByBestSellingJob;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Parameter;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TDD Tests - BestSelling Parameter Disabled
 *
 * Validates that when BEST_SELLING_CATEGORY_AUTO_GENERATE is "0",
 * both existing commands (generate-best-selling-category and
 * order-category-products) do NOT execute.
 *
 * Tests 10-11 validate existing behavior (should PASS - green).
 * Test 12 validates edge case with numeric 0.
 * Test 13 validates both commands run when parameter is "1".
 */
class BestSellingParameterDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected Role $cafeRole;

    protected Permission $consolidadoPermission;

    protected Company $company;

    protected PriceList $priceList;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $this->consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        $this->priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $this->company = Company::create([
            'name' => 'TEST COMPANY',
            'fantasy_name' => 'TEST',
            'address' => 'Test Address',
            'email' => 'test@test.com',
            'phone_number' => '123456789',
            'registration_number' => 'REG-001',
            'description' => 'Test company',
            'active' => true,
            'tax_id' => '123456789',
            'price_list_id' => $this->priceList->id,
        ]);

        $this->branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Test Address',
            'company_id' => $this->company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createParameterWithValue(string $value): void
    {
        Parameter::firstOrCreate(
            ['name' => Parameter::BEST_SELLING_CATEGORY_AUTO_GENERATE],
            [
                'description' => 'Auto generate best selling category',
                'value_type' => 'boolean',
                'value' => $value,
                'active' => true,
            ]
        );

        // Force the value in case it already existed
        Parameter::where('name', Parameter::BEST_SELLING_CATEGORY_AUTO_GENERATE)
            ->update(['value' => $value, 'active' => true]);

        Parameter::firstOrCreate(
            ['name' => Parameter::BEST_SELLING_CATEGORY_DATE_RANGE_DAYS],
            [
                'description' => 'Date range days',
                'value_type' => 'integer',
                'value' => '30',
                'active' => true,
            ]
        );

        Parameter::firstOrCreate(
            ['name' => Parameter::BEST_SELLING_CATEGORY_PRODUCTS_LIMIT],
            [
                'description' => 'Products limit',
                'value_type' => 'integer',
                'value' => '10',
                'active' => true,
            ]
        );
    }

    protected function createCafeMenu(string $publicationDate, bool $productsOrdered = false): Menu
    {
        return Menu::create([
            'title' => "TEST MENU {$publicationDate}",
            'publication_date' => $publicationDate,
            'max_order_date' => Carbon::parse($publicationDate)->subDay()->setTime(15, 30)->format('Y-m-d H:i:s'),
            'role_id' => $this->cafeRole->id,
            'permissions_id' => $this->consolidadoPermission->id,
            'active' => true,
            'products_ordered' => $productsOrdered,
        ]);
    }

    protected function createCategoryWithProducts(int $productCount = 2): array
    {
        $category = Category::create([
            'name' => 'TEST CATEGORY',
            'description' => 'Test',
            'is_active' => true,
            'is_dynamic' => false,
        ]);

        $products = [];
        for ($i = 1; $i <= $productCount; $i++) {
            $product = Product::create([
                'name' => "Product {$i}",
                'code' => 'PROD-'.$i.'-'.uniqid(),
                'description' => 'Test',
                'category_id' => $category->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);

            PriceListLine::create([
                'price_list_id' => $this->priceList->id,
                'product_id' => $product->id,
                'unit_price' => 100000,
                'active' => true,
            ]);

            $products[] = $product;
        }

        return ['category' => $category, 'products' => $products];
    }

    // =========================================================================
    // TEST 10: generate-best-selling-category does not run when param = "0"
    // =========================================================================

    public function test_generate_best_selling_category_does_not_run_when_parameter_is_zero_string(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $this->createParameterWithValue('0');

        $this->createCafeMenu('2026-02-27');

        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('Auto-generation of best-selling category is disabled.');
    }

    // =========================================================================
    // TEST 11: order-category-products does not run when param = "0"
    // =========================================================================

    public function test_order_category_products_does_not_run_when_parameter_is_zero_string(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $this->createParameterWithValue('0');

        $this->createCafeMenu('2026-02-27');

        $this->artisan('menus:order-category-products')
            ->assertSuccessful()
            ->expectsOutput('Auto-ordering of category menu products is disabled.');

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // TEST 12: generate-best-selling-category does not run when param = 0 (numeric)
    // =========================================================================

    public function test_generate_best_selling_category_does_not_run_when_parameter_is_zero_numeric(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $this->createParameterWithValue('0');

        // Verify the cast: (bool) "0" = false
        $paramValue = Parameter::getValue(Parameter::BEST_SELLING_CATEGORY_AUTO_GENERATE, true);
        $this->assertFalse($paramValue, 'Parameter with value "0" should evaluate to false');

        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('Auto-generation of best-selling category is disabled.');
    }

    // =========================================================================
    // TEST 13: Both commands run when parameter is "1"
    // =========================================================================

    public function test_both_commands_run_when_parameter_is_one(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $this->createParameterWithValue('1');

        $data = $this->createCategoryWithProducts(2);

        // Create menu with products for order-category-products
        $menu = $this->createCafeMenu('2026-02-27');

        $categoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $categoryMenu->products()->attach([
            $data['products'][0]->id => ['display_order' => 1],
            $data['products'][1]->id => ['display_order' => 2],
        ]);

        // Create user with orders for sales data
        $user = User::create([
            'name' => 'TEST USER',
            'nickname' => 'TEST',
            'email' => 'test@user.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'active' => true,
        ]);
        $user->roles()->attach($this->cafeRole->id);
        $user->permissions()->attach($this->consolidadoPermission->id);

        // generate-best-selling-category should proceed (not show "disabled" message)
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->doesntExpectOutput('Auto-generation of best-selling category is disabled.');

        // order-category-products should dispatch jobs
        $this->artisan('menus:order-category-products')
            ->assertSuccessful()
            ->doesntExpectOutput('Auto-ordering of category menu products is disabled.');

        Queue::assertPushed(OrderCategoryMenuProductsByBestSellingJob::class);
    }
}
