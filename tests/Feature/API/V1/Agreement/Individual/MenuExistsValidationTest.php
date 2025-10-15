<?php

namespace Tests\Feature\API\V1\Agreement\Individual;

use Tests\BaseIndividualAgreementTest;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\Permission;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test MenuExistsValidation for Individual Agreement users
 *
 * This validation ensures a menu exists for the requested date
 * when user has allow_late_orders = true
 *
 * NOTE: This test uses the default subcategory exclusion rules from BaseIndividualAgreementTest.
 * To customize the rules, override the getSubcategoryExclusions() method:
 *
 * protected function getSubcategoryExclusions(): array
 * {
 *     return [
 *         'CUSTOM_SUBCATEGORY' => ['EXCLUDED_SUBCATEGORY_1', 'EXCLUDED_SUBCATEGORY_2'],
 *         'ANOTHER_SUBCATEGORY' => ['EXCLUDED_SUBCATEGORY_3'],
 *     ];
 * }
 */
class MenuExistsValidationTest extends BaseIndividualAgreementTest
{

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time to 2025-10-14 (test creation date)
        Carbon::setTestNow('2025-10-14 00:00:00');
    }

    protected function tearDown(): void
    {
        // Release frozen time after each test
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test that order creation fails when no menu exists for the date
     */
    public function test_order_creation_fails_when_no_menu_exists_for_date(): void
    {
        // ===  GET ROLES AND PERMISSIONS (created in BaseIndividualAgreementTest) ===
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => true,
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
            'name' => 'Main Branch',
            'address' => 'Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // === CREATE USER WITH allow_late_orders = true ===
        $user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.NO.MENU',
            'email' => 'test.no.menu@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'allow_late_orders' => true, // KEY: This enables menu validation
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === NO MENU CREATED FOR 2025-10-14 ===
        // Intentionally not creating any menu

        // === TEST: Try to create order for date without menu ===
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/create-or-update-order/2025-10-14", [
            'order_lines' => []
        ]);

        // === ASSERTIONS ===
        // The authorize() method in CreateOrUpdateOrderRequest returns false when no menu exists
        // This causes a 403 Forbidden (Authorization failure) instead of 422 Validation Error
        // This happens BEFORE MenuExistsValidation is executed in the controller
        $response->assertStatus(403);
    }

    /**
     * Test that order update status fails when no menu exists for the date
     */
    public function test_order_status_update_fails_when_no_menu_exists_for_date(): void
    {
        // ===  GET ROLES AND PERMISSIONS (created in BaseIndividualAgreementTest) ===
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => true,
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
            'name' => 'Main Branch',
            'address' => 'Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // === CREATE USER WITH allow_late_orders = true ===
        $user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.NO.MENU.STATUS',
            'email' => 'test.no.menu.status@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'allow_late_orders' => true, // KEY: This enables menu validation
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // === NO MENU CREATED FOR 2025-10-14 ===
        // Intentionally not creating any menu

        // === TEST: Try to update order status for date without menu ===
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-14", [
            'status' => 'PROCESSED'
        ]);

        // === ASSERTIONS ===
        // The authorize() method in UpdateStatusRequest returns false when no menu exists
        // This causes a 403 Forbidden (Authorization failure) instead of 422 Validation Error
        // This happens BEFORE MenuExistsValidation is executed in the controller
        $response->assertStatus(403);
    }
}
