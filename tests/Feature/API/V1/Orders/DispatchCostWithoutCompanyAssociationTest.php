<?php

namespace Tests\Feature\API\V1\Orders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\CategoryLine;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\DispatchRule;
use App\Models\DispatchRuleRange;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dispatch Cost - Branch associated but Company NOT associated to dispatch rule.
 *
 * Scenario replicating production dispatch rule "DESPACHO MINIMO 70.000":
 *   - Range: $0 - $70.000 => dispatch cost $8.000
 *   - Range: $70.001+     => dispatch cost $0
 *
 * The dispatch rule is associated to the BRANCH only, NOT to the company.
 * Validates that the API returns correct dispatch cost information.
 */
class DispatchCostWithoutCompanyAssociationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dispatch_cost_when_only_branch_is_associated_to_rule(): void
    {
        // 1. Roles and permissions
        $role = Role::create(['name' => RoleName::CAFE->value]);
        $permission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // 2. Price list
        $priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'is_global' => false,
            'min_price_order' => 0,
        ]);

        // 3. Company and branch
        $company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TESTCOMP001',
            'fantasy_name' => 'Test Company',
            'address' => 'Company Address 123',
            'email' => 'test.company@test.com',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'address' => 'Branch Address 456',
            'min_price_order' => 0,
        ]);

        // 4. Dispatch rule - same ranges as production "DESPACHO MINIMO 70.000"
        $dispatchRule = DispatchRule::create([
            'name' => 'TEST DESPACHO MIN 70K',
            'active' => true,
            'priority' => 1,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        // ONLY associate the BRANCH, NOT the company
        $dispatchRule->branches()->attach($branch->id);
        // $dispatchRule->companies()->attach($company->id); // intentionally NOT associated

        DispatchRuleRange::create([
            'dispatch_rule_id' => $dispatchRule->id,
            'min_amount' => 0,
            'max_amount' => 7000000, // $70.000
            'dispatch_cost' => 800000, // $8.000
        ]);

        DispatchRuleRange::create([
            'dispatch_rule_id' => $dispatchRule->id,
            'min_amount' => 7000001,
            'max_amount' => null,
            'dispatch_cost' => 0,
        ]);

        // 5. User
        $user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.USER',
            'email' => 'test.user@test.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'validate_subcategory_rules' => false,
        ]);

        $user->roles()->attach($role->id);
        $user->permissions()->attach($permission->id);

        // 6. Menu for the date
        $menu = Menu::create([
            'title' => 'TEST MENU 2026-03-03',
            'description' => 'Test menu',
            'publication_date' => '2026-03-03',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        // 7. Category with product
        $categoryGroup = CategoryGroup::create(['name' => 'entradas']);
        $subcategory = Subcategory::create(['name' => 'ENTRADA']);

        $category = Category::create([
            'name' => 'TEST CATEGORY',
            'description' => 'Test category',
            'category_group_id' => $categoryGroup->id,
        ]);

        $category->subcategories()->attach($subcategory->id);

        CategoryLine::create([
            'category_id' => $category->id,
            'weekday' => 'tuesday',
            'preparation_days' => 0,
            'maximum_order_time' => '15:00:00',
            'active' => true,
        ]);

        // 8. Product
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test product',
            'code' => 'TESTPROD001',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 450000, // $4.500
        ]);

        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'order' => 1,
            'show_all_products' => true,
        ]);

        $categoryMenu->products()->attach($product->id);

        // 9. Auth
        Sanctum::actingAs($user);

        // 10. Create order with 1 product ($4.500 total - within $0-$70K range)
        $date = '2026-03-03';
        $response = $this->postJson("/api/v1/orders/create-or-update-order/{$date}", [
            'order_lines' => [
                [
                    'id' => $product->id,
                    'quantity' => 1,
                    'partially_scheduled' => false,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $orderData = $response->json('data');

        // 11. Assert dispatch cost
        // The dispatch rule requires BOTH company AND branch to be associated
        // (see DispatchRuleRepository::findApplicableRule).
        // Since company is NOT associated, the rule should NOT apply.
        // Therefore dispatch_cost should be $0.
        $this->assertEquals('$0', $orderData['dispatch_cost'],
            'Dispatch cost should be $0 when company is NOT associated to the dispatch rule'
        );

        // shipping_threshold should also reflect no applicable rule
        $this->assertFalse(
            $orderData['shipping_threshold']['has_better_rate'],
            'No better rate should exist when dispatch rule does not apply'
        );
    }
}
