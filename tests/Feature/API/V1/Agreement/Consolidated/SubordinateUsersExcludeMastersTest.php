<?php

namespace Tests\Feature\API\V1\Agreement\Consolidated;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Production Bug Test - Subordinate Users API Returns Master Users
 *
 * PRODUCTION DATA (anonymized):
 * - Master User: TEST.MASTER.USER (production: WC.BANDERA, ID: 135)
 * - Company: TEST CONSOLIDATED COMPANY SPA (production: HUERTO SUR CAFETERIAS SPA, ID: 568)
 * - Total users in company: 25 (23 subordinates + 2 masters)
 * - Master users in company: 2
 *   - WC.BANDERA (ID: 135)
 *   - WC.MAESTRO.HUERTO (ID: 349)
 *
 * SCENARIO:
 * A master user (master_user = true) calls GET /api/v1/users/subordinates
 * to get the list of subordinate users in their company.
 *
 * EXPECTED BEHAVIOR:
 * The API should return ONLY subordinate users (master_user = false).
 * Master users should NOT be included in the response.
 *
 * ACTUAL BUG:
 * The API currently returns ALL users from the company, including master users.
 * In production, it returns 25 users when it should return only 23.
 *
 * ROOT CAUSE:
 * UsersController::getSubordinateUsers() (line 36) queries:
 * User::where('company_id', $masterUser->company_id)
 *
 * This query does NOT filter out master_user = true, so it returns everyone.
 *
 * API ENDPOINT: GET /api/v1/users/subordinates
 */
class SubordinateUsersExcludeMastersTest extends TestCase
{
    use RefreshDatabase;

    public function test_subordinate_users_endpoint_must_exclude_master_users(): void
    {
        // 1. CREATE ROLES AND PERMISSIONS
        $role = Role::create(['name' => RoleName::CAFE->value]);
        $permission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // 2. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'TEST CONSOLIDATED PRICE LIST',
            'is_global' => false,
            'min_price_order' => 0,
        ]);

        // 3. CREATE COMPANY AND BRANCH
        $company = Company::create([
            'name' => 'TEST CONSOLIDATED COMPANY SPA',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TESTCONS001',
            'fantasy_name' => 'Test Consolidated Company',
            'address' => 'Test Address 123',
            'email' => 'test.consolidated@test.com',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'address' => 'Branch Address 456',
            'min_price_order' => 0,
        ]);

        // 4. CREATE MASTER USER (the one calling the API)
        $masterUser = User::create([
            'name' => 'Test Master User',
            'nickname' => 'TEST.MASTER.USER',
            'email' => 'test.master@test.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'master_user' => true, // This is a MASTER user
        ]);

        $masterUser->roles()->attach($role->id);
        $masterUser->permissions()->attach($permission->id);

        // 5. CREATE ANOTHER MASTER USER (should be excluded from results)
        $anotherMasterUser = User::create([
            'name' => 'Another Master User',
            'nickname' => 'TEST.MASTER.TWO',
            'email' => 'test.master.two@test.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'master_user' => true, // This is also a MASTER user
        ]);

        $anotherMasterUser->roles()->attach($role->id);
        $anotherMasterUser->permissions()->attach($permission->id);

        // 6. CREATE SUBORDINATE USERS (should be included in results)
        $subordinate1 = User::create([
            'name' => 'Subordinate User 1',
            'nickname' => 'TEST.SUBORDINATE.1',
            'email' => 'test.sub1@test.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'master_user' => false, // Subordinate
        ]);

        $subordinate1->roles()->attach($role->id);
        $subordinate1->permissions()->attach($permission->id);

        $subordinate2 = User::create([
            'name' => 'Subordinate User 2',
            'nickname' => 'TEST.SUBORDINATE.2',
            'email' => 'test.sub2@test.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'master_user' => false, // Subordinate
        ]);

        $subordinate2->roles()->attach($role->id);
        $subordinate2->permissions()->attach($permission->id);

        $subordinate3 = User::create([
            'name' => 'Subordinate User 3',
            'nickname' => 'TEST.SUBORDINATE.3',
            'email' => 'test.sub3@test.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'master_user' => false, // Subordinate
        ]);

        $subordinate3->roles()->attach($role->id);
        $subordinate3->permissions()->attach($permission->id);

        // Company now has: 2 master users + 3 subordinate users = 5 total

        // 7. AUTHENTICATE AS MASTER USER
        Sanctum::actingAs($masterUser);

        // 8. CALL THE API
        $response = $this->getJson('/api/v1/users/subordinates');

        // 9. ASSERT RESPONSE STRUCTURE (paginated response)
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'current_page',
                'data' => [
                    '*' => [
                        'nickname',
                        'email',
                        'branch_name',
                        'branch_address',
                        'available_menus',
                    ],
                ],
                'last_page',
                'per_page',
                'total',
            ],
        ]);

        $users = $response->json('data.data');

        // 10. ASSERT EXPECTED BEHAVIOR
        // BUG: This assertion will FAIL
        // The API currently returns 5 users (2 masters + 3 subordinates)
        // It should return only 3 subordinate users
        $this->assertCount(3, $users,
            'API should return only subordinate users (3), excluding master users');

        // 11. VERIFY NO MASTER USERS ARE IN THE RESPONSE
        foreach ($users as $user) {
            // Extract nickname from response
            $nickname = $user['nickname'];

            // Find the user in database
            $dbUser = User::where('nickname', $nickname)->first();

            // Assert that this user is NOT a master user
            $this->assertFalse($dbUser->master_user,
                "User {$nickname} is a master user and should NOT be in subordinates list");
        }

        // 12. VERIFY SPECIFIC USERS
        $nicknames = collect($users)->pluck('nickname')->toArray();

        // Master users should NOT be in the list
        $this->assertNotContains('TEST.MASTER.USER', $nicknames,
            'Master user TEST.MASTER.USER should NOT be in subordinates list');
        $this->assertNotContains('TEST.MASTER.TWO', $nicknames,
            'Master user TEST.MASTER.TWO should NOT be in subordinates list');

        // Subordinate users SHOULD be in the list
        $this->assertContains('TEST.SUBORDINATE.1', $nicknames,
            'Subordinate user TEST.SUBORDINATE.1 should be in the list');
        $this->assertContains('TEST.SUBORDINATE.2', $nicknames,
            'Subordinate user TEST.SUBORDINATE.2 should be in the list');
        $this->assertContains('TEST.SUBORDINATE.3', $nicknames,
            'Subordinate user TEST.SUBORDINATE.3 should be in the list');
    }
}