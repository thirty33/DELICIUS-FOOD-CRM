<?php

namespace Tests\Feature\Imports;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Imports\CompanyBranchesImport;
use App\Imports\Concerns\BranchColumnDefinition;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\CategoryLine;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\DispatchRule;
use App\Models\DispatchRuleRange;
use App\Models\ImportProcess;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use App\Repositories\DispatchRuleRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * Integration test: Branch import with dispatch rule → API dispatch cost.
 *
 * Verifies that importing a branch with a dispatch rule correctly associates
 * BOTH the branch AND the company to the rule, so that the API returns
 * the correct dispatch_cost via DispatchRuleRepository::findApplicableRule().
 *
 * findApplicableRule() requires BOTH:
 *   - dispatch_rule_branches pivot (branch_id)
 *   - dispatch_rule_companies pivot (company_id)
 *
 * Fixture: tests/Fixtures/test_branch_import.xlsx
 *   Row 2: BR-MAIN (REG-001) → "DESPACHO TEST 60K"
 *   Row 4: BR-SUR (REG-002)  → empty (detach)
 */
class BranchImportDispatchCostIntegrationTest extends TestCase
{
    use ConfiguresImportTests;
    use RefreshDatabase;

    private DispatchRule $rule60k;

    private DispatchRule $rule35k;

    private Company $company1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureImportTest();
        Carbon::setTestNow('2026-03-03 10:00:00');

        $priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'is_global' => false,
            'min_price_order' => 0,
        ]);

        $this->company1 = Company::create([
            'name' => 'TEST COMPANY REG-001',
            'fantasy_name' => 'TEST CO 001',
            'registration_number' => 'REG-001',
            'email' => 'reg001@test.cl',
            'price_list_id' => $priceList->id,
        ]);

        Company::create([
            'name' => 'TEST COMPANY REG-002',
            'fantasy_name' => 'TEST CO 002',
            'registration_number' => 'REG-002',
            'email' => 'reg002@test.cl',
            'price_list_id' => $priceList->id,
        ]);

        Company::create([
            'name' => 'TEST COMPANY REG-003',
            'fantasy_name' => 'TEST CO 003',
            'registration_number' => 'REG-003',
            'email' => 'reg003@test.cl',
            'price_list_id' => $priceList->id,
        ]);

        // Dispatch rules (no associations — the import must create them)
        $this->rule60k = DispatchRule::create([
            'name' => 'DESPACHO TEST 60K',
            'active' => true,
            'priority' => 1,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        DispatchRuleRange::create([
            'dispatch_rule_id' => $this->rule60k->id,
            'min_amount' => 0,
            'max_amount' => 6000000,
            'dispatch_cost' => 500000,
        ]);

        DispatchRuleRange::create([
            'dispatch_rule_id' => $this->rule60k->id,
            'min_amount' => 6000001,
            'max_amount' => null,
            'dispatch_cost' => 0,
        ]);

        $this->rule35k = DispatchRule::create([
            'name' => 'DESPACHO TEST 35K',
            'active' => true,
            'priority' => 2,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        // Menu and product setup for API
        $role = Role::create(['name' => RoleName::CAFE->value]);
        $permission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        $menu = Menu::create([
            'title' => 'TEST MENU',
            'description' => 'Test',
            'publication_date' => '2026-03-03',
            'max_order_date' => '2026-03-03 18:00:00',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        $categoryGroup = CategoryGroup::create(['name' => 'entradas']);
        $subcategory = Subcategory::create(['name' => 'ENTRADA']);

        $category = Category::create([
            'name' => 'TEST CATEGORY',
            'description' => 'Test',
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

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
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
            'unit_price' => 450000,
        ]);

        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'order' => 1,
            'show_all_products' => true,
        ]);
        $categoryMenu->products()->attach($product->id);

        $user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.USER',
            'email' => 'test.user@test.com',
            'password' => Hash::make('password'),
            'company_id' => $this->company1->id,
            'branch_id' => null,
            'validate_subcategory_rules' => false,
        ]);
        $user->roles()->attach($role->id);
        $user->permissions()->attach($permission->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runImport(): ImportProcess
    {
        Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_BRANCHES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new CompanyBranchesImport($importProcess->id),
            base_path('tests/Fixtures/test_branch_import.xlsx')
        );

        return $importProcess->fresh();
    }

    /**
     * Import BR-MAIN with "DESPACHO TEST 60K" → create order via API → verify dispatch_cost.
     *
     * The import must associate BOTH the branch AND the company to the dispatch rule.
     * Without company association, findApplicableRule() returns null and dispatch_cost = $0.
     */
    public function test_imported_branch_dispatch_rule_applies_in_api_order(): void
    {
        $importProcess = $this->runImport();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should succeed. Errors: '.json_encode($importProcess->error_log)
        );

        $branch = Branch::where('branch_code', 'BR-MAIN')->first();
        $this->assertNotNull($branch);

        // Dispatch rule associated to branch
        $this->assertEquals(1, $branch->dispatchRules()->count(), 'Branch should have dispatch rule');
        $this->assertEquals($this->rule60k->id, $branch->dispatchRules()->first()->id);

        // Dispatch rule ALSO associated to the company
        $this->assertTrue(
            $this->rule60k->companies()->where('company_id', $this->company1->id)->exists(),
            'Company must also be associated to the dispatch rule for findApplicableRule() to work'
        );

        // findApplicableRule works (same query the API uses)
        $dispatchRuleRepo = new DispatchRuleRepository;
        $user = User::where('email', 'test.user@test.com')->first();
        $user->update(['branch_id' => $branch->id]);

        $applicableRule = $dispatchRuleRepo->getDispatchRuleForUser($user->fresh());
        $this->assertNotNull($applicableRule, 'findApplicableRule must find the rule');
        $this->assertEquals($this->rule60k->id, $applicableRule->id);

        // Create order via API → dispatch_cost must reflect the rule
        Sanctum::actingAs($user->fresh());

        $product = Product::where('code', 'TESTPROD001')->first();
        $response = $this->postJson('/api/v1/orders/create-or-update-order/2026-03-03', [
            'order_lines' => [
                ['id' => $product->id, 'quantity' => 1, 'partially_scheduled' => false],
            ],
        ]);

        $response->assertStatus(200);

        $orderData = $response->json('data');

        // Order total = $4.500 → range $0-$60K → dispatch cost = $5.000
        $this->assertEquals('$5.000', $orderData['dispatch_cost'],
            'Dispatch cost should be $5.000 for order within $0-$60K range'
        );

        $this->assertTrue($orderData['shipping_threshold']['has_better_rate']);
    }

    /**
     * Import BR-SUR with empty dispatch rule → detaches the branch from the rule.
     *
     * The company pivot is NOT detached because other branches of the same company
     * may still be associated to the rule. findApplicableRule() requires BOTH pivots,
     * so a branch without the branch pivot will never match regardless of company pivot.
     */
    public function test_imported_branch_empty_rule_detaches_branch_from_rule(): void
    {
        $company2 = Company::where('registration_number', 'REG-002')->first();
        $branchSur = Branch::create([
            'company_id' => $company2->id,
            'branch_code' => 'BR-SUR',
            'fantasy_name' => 'Old Sur',
            'min_price_order' => 0,
        ]);

        $this->rule60k->branches()->attach($branchSur->id);
        $this->rule60k->companies()->attach($company2->id);

        $this->assertEquals(1, $branchSur->dispatchRules()->count());
        $this->assertTrue($this->rule60k->companies()->where('company_id', $company2->id)->exists());

        $this->runImport();

        $branchSur->refresh();
        $this->rule60k->refresh();

        $this->assertEquals(0, $branchSur->dispatchRules()->count(),
            'Branch dispatch rule should be detached when Excel column is empty'
        );

        // Company pivot remains — it is functionally inert without the branch pivot
        $this->assertTrue(
            $this->rule60k->companies()->where('company_id', $company2->id)->exists(),
            'Company pivot should remain (safe to leave; findApplicableRule needs both pivots)'
        );
    }

    /**
     * 1 company, 6 branches, 2 dispatch rules with pre-existing associations.
     *
     * Before import:
     *   Rule A (60K, priority 1): company + BR-A1, BR-A2, BR-A3 → $5.000 for orders < $60K
     *   Rule B (35K, priority 2): company + BR-A4, BR-A5         → $3.000 for orders < $35K
     *   BR-A6: no dispatch rule
     *
     * Import: single row associating BR-A6 → Rule B ("DESPACHO TEST 35K")
     *
     * After import, each branch user creates an order ($4.500) and the API must return:
     *   BR-A1..A3 → $5.000 (Rule A)
     *   BR-A4..A6 → $3.000 (Rule B, BR-A6 newly associated by import)
     */
    public function test_six_branches_two_rules_import_associates_sixth_branch_correctly(): void
    {
        // --- Ranges for Rule B (rule60k already has ranges from setUp) ---
        DispatchRuleRange::create([
            'dispatch_rule_id' => $this->rule35k->id,
            'min_amount' => 0,
            'max_amount' => 3500000,
            'dispatch_cost' => 300000,
        ]);

        DispatchRuleRange::create([
            'dispatch_rule_id' => $this->rule35k->id,
            'min_amount' => 3500001,
            'max_amount' => null,
            'dispatch_cost' => 0,
        ]);

        // --- 6 branches for company1 ---
        $branches = [];
        for ($i = 1; $i <= 6; $i++) {
            $branches[$i] = Branch::create([
                'company_id' => $this->company1->id,
                'branch_code' => "BR-A{$i}",
                'fantasy_name' => "Branch A{$i}",
                'min_price_order' => 0,
            ]);
        }

        // --- Pre-existing dispatch rule associations ---
        // Rule A (60K): company + BR-A1, BR-A2, BR-A3
        $this->rule60k->companies()->attach($this->company1->id);
        $this->rule60k->branches()->attach([
            $branches[1]->id,
            $branches[2]->id,
            $branches[3]->id,
        ]);

        // Rule B (35K): company + BR-A4, BR-A5
        $this->rule35k->companies()->attach($this->company1->id);
        $this->rule35k->branches()->attach([
            $branches[4]->id,
            $branches[5]->id,
        ]);

        // BR-A6 has no dispatch rule yet

        // --- Generate Excel fixture with single row: BR-A6 → "DESPACHO TEST 35K" ---
        $fixturePath = $this->generateSingleBranchFixture(
            'REG-001',
            'BR-A6',
            'Branch A6 Updated',
            'DESPACHO TEST 35K'
        );

        // --- Run import ---
        Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_BRANCHES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new CompanyBranchesImport($importProcess->id),
            $fixturePath
        );

        $importProcess->refresh();
        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should succeed. Errors: '.json_encode($importProcess->error_log)
        );

        // --- Verify BR-A6 is now associated to Rule B ---
        $branches[6]->refresh();
        $this->assertEquals(1, $branches[6]->dispatchRules()->count(), 'BR-A6 should have dispatch rule after import');
        $this->assertEquals($this->rule35k->id, $branches[6]->dispatchRules()->first()->id);

        // --- Create 6 users (one per branch) and verify dispatch cost via API ---
        $role = Role::where('name', RoleName::CAFE->value)->first();
        $permission = Permission::where('name', PermissionName::CONSOLIDADO->value)->first();
        $product = Product::where('code', 'TESTPROD001')->first();

        $expectedCosts = [
            1 => '$5.000', // Rule A
            2 => '$5.000', // Rule A
            3 => '$5.000', // Rule A
            4 => '$3.000', // Rule B
            5 => '$3.000', // Rule B
            6 => '$3.000', // Rule B (newly imported)
        ];

        foreach ($expectedCosts as $branchNum => $expectedCost) {
            $user = User::create([
                'name' => "User Branch A{$branchNum}",
                'nickname' => "USER.A{$branchNum}",
                'email' => "user.a{$branchNum}@test.com",
                'password' => Hash::make('password'),
                'company_id' => $this->company1->id,
                'branch_id' => $branches[$branchNum]->id,
                'validate_subcategory_rules' => false,
            ]);
            $user->roles()->attach($role->id);
            $user->permissions()->attach($permission->id);

            Sanctum::actingAs($user);

            $response = $this->postJson('/api/v1/orders/create-or-update-order/2026-03-03', [
                'order_lines' => [
                    ['id' => $product->id, 'quantity' => 1, 'partially_scheduled' => false],
                ],
            ]);

            $response->assertStatus(200);

            $orderData = $response->json('data');
            $this->assertEquals(
                $expectedCost,
                $orderData['dispatch_cost'],
                "BR-A{$branchNum}: expected dispatch_cost={$expectedCost}, got={$orderData['dispatch_cost']}"
            );
        }

        // Cleanup generated fixture
        @unlink($fixturePath);
    }

    /**
     * Generate an Excel fixture with a single branch row.
     */
    private function generateSingleBranchFixture(
        string $registrationNumber,
        string $branchCode,
        string $fantasyName,
        string $dispatchRuleName
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = BranchColumnDefinition::headers();
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }

        $rowData = [
            $registrationNumber,  // Número de Registro de Compañía
            $branchCode,          // Código
            $fantasyName,         // Nombre de Fantasía
            '',                   // Dirección
            '',                   // Dirección de Despacho
            '',                   // Nombre de Contacto
            '',                   // Apellido de Contacto
            '',                   // Teléfono de Contacto
            0,                    // Precio Pedido Mínimo
            $dispatchRuleName,    // Regla de Transporte
        ];

        foreach ($rowData as $colIndex => $value) {
            $sheet->setCellValueByColumnAndRow($colIndex + 1, 2, $value);
        }

        $filePath = base_path('tests/Fixtures/test_branch_import_six_branches.xlsx');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);

        return $filePath;
    }
}
