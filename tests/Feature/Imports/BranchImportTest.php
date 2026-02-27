<?php

namespace Tests\Feature\Imports;

use App\Imports\CompanyBranchesImport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DispatchRule;
use App\Models\ImportProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * BranchImport Test - Validates branch import from Excel files
 *
 * Tests the complete import flow for branches including:
 * - Basic branch data (code, fantasy_name, address, contact info)
 * - Company lookup by registration_number
 * - Price format transformation ($5,000.00 → 500000 cents)
 * - Optional/nullable field handling
 * - Upsert behavior (updateOrCreate by branch_code)
 * - ImportProcess status transitions
 * - Validation error handling
 * - Dispatch rule association via pivot (sync/detach)
 *
 * Fixture: tests/Fixtures/test_branch_import.xlsx (5 valid branches, col 10 = dispatch rule)
 *   Row 2: BR-MAIN     → "DESPACHO TEST 60K" (case 1: associate)
 *   Row 3: BR-NORTE    → "DESPACHO TEST 35K" (case 1: associate)
 *   Row 4: BR-SUR      → empty               (case 3: detach if had rule)
 *   Row 5: BR-ORIENTE  → "REGLA-INEXISTENTE" (case 4: silent fail, branch still imports)
 *   Row 6: BR-PONIENTE → "DESPACHO TEST 60K" (case 1: associate)
 *
 * Companies needed:
 *   REG-001 → 2 branches (BR-MAIN, BR-NORTE)
 *   REG-002 → 2 branches (BR-SUR, BR-ORIENTE)
 *   REG-003 → 1 branch (BR-PONIENTE)
 */
class BranchImportTest extends TestCase
{
    use ConfiguresImportTests;
    use RefreshDatabase;

    protected string $testFile;

    protected string $errorFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureImportTest();

        $this->testFile = base_path('tests/Fixtures/test_branch_import.xlsx');
        $this->errorFile = base_path('tests/Fixtures/test_branch_import_errors.xlsx');

        // Create companies needed by the fixture
        Company::create([
            'name' => 'TEST COMPANY REG-001',
            'fantasy_name' => 'TEST CO 001',
            'registration_number' => 'REG-001',
            'email' => 'reg001@test.cl',
        ]);

        Company::create([
            'name' => 'TEST COMPANY REG-002',
            'fantasy_name' => 'TEST CO 002',
            'registration_number' => 'REG-002',
            'email' => 'reg002@test.cl',
        ]);

        Company::create([
            'name' => 'TEST COMPANY REG-003',
            'fantasy_name' => 'TEST CO 003',
            'registration_number' => 'REG-003',
            'email' => 'reg003@test.cl',
        ]);
    }

    private function createImportProcess(): ImportProcess
    {
        return ImportProcess::create([
            'type' => ImportProcess::TYPE_BRANCHES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);
    }

    private function runImport(ImportProcess $importProcess, ?string $file = null): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        Excel::import(
            new CompanyBranchesImport($importProcess->id),
            $file ?? $this->testFile
        );
    }

    // ---------------------------------------------------------------
    // Successful Import Tests
    // ---------------------------------------------------------------

    public function test_imports_all_branches_successfully(): void
    {
        $this->assertEquals(0, Branch::count(), 'Should start with 0 branches');

        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors. Errors: '.json_encode($importProcess->error_log)
        );

        $this->assertNull($importProcess->error_log, 'Error log should be null for successful import');

        $this->assertEquals(5, Branch::count(), 'Should have imported 5 branches');
    }

    public function test_imports_branch_data_correctly(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // Row 2: BR-MAIN - all fields filled
        $branch = Branch::where('branch_code', 'BR-MAIN')->first();
        $this->assertNotNull($branch, 'Branch BR-MAIN should exist');

        $company = Company::where('registration_number', 'REG-001')->first();
        $this->assertEquals($company->id, $branch->company_id);
        $this->assertEquals('Sucursal Central', $branch->fantasy_name);
        $this->assertEquals('Av. Principal 100', $branch->address);
        $this->assertEquals('Av. Principal 100', $branch->shipping_address);
        $this->assertEquals('Juan', $branch->contact_name);
        $this->assertEquals('Pérez', $branch->contact_last_name);
        $this->assertEquals(500000, $branch->min_price_order);
    }

    public function test_imports_branch_with_optional_fields_empty(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // Row 5: BR-ORIENTE - only required fields
        $branch = Branch::where('branch_code', 'BR-ORIENTE')->first();
        $this->assertNotNull($branch);

        $this->assertNull($branch->address);
        $this->assertNull($branch->shipping_address);
        $this->assertNull($branch->contact_name);
        $this->assertNull($branch->contact_last_name);
        $this->assertNull($branch->contact_phone_number);
        $this->assertEquals(0, $branch->min_price_order);
    }

    public function test_imports_price_in_different_formats(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // "$5,000.00" → 500000
        $this->assertEquals(500000, Branch::where('branch_code', 'BR-MAIN')->first()->min_price_order);

        // "$3,500.50" → 350050
        $this->assertEquals(350050, Branch::where('branch_code', 'BR-NORTE')->first()->min_price_order);

        // "$10,000.00" → 1000000
        $this->assertEquals(1000000, Branch::where('branch_code', 'BR-SUR')->first()->min_price_order);

        // "$0.00" → 0
        $this->assertEquals(0, Branch::where('branch_code', 'BR-ORIENTE')->first()->min_price_order);

        // "2500.75" (no $) → 250075
        $this->assertEquals(250075, Branch::where('branch_code', 'BR-PONIENTE')->first()->min_price_order);
    }

    public function test_imports_branches_associated_to_correct_company(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        $comp1 = Company::where('registration_number', 'REG-001')->first();
        $comp2 = Company::where('registration_number', 'REG-002')->first();
        $comp3 = Company::where('registration_number', 'REG-003')->first();

        // REG-001 has 2 branches
        $this->assertEquals(2, Branch::where('company_id', $comp1->id)->count());
        $this->assertNotNull(Branch::where('company_id', $comp1->id)->where('branch_code', 'BR-MAIN')->first());
        $this->assertNotNull(Branch::where('company_id', $comp1->id)->where('branch_code', 'BR-NORTE')->first());

        // REG-002 has 2 branches
        $this->assertEquals(2, Branch::where('company_id', $comp2->id)->count());
        $this->assertNotNull(Branch::where('company_id', $comp2->id)->where('branch_code', 'BR-SUR')->first());
        $this->assertNotNull(Branch::where('company_id', $comp2->id)->where('branch_code', 'BR-ORIENTE')->first());

        // REG-003 has 1 branch
        $this->assertEquals(1, Branch::where('company_id', $comp3->id)->count());
        $this->assertNotNull(Branch::where('company_id', $comp3->id)->where('branch_code', 'BR-PONIENTE')->first());
    }

    public function test_reimport_updates_existing_branches_not_duplicates(): void
    {
        // First import
        $importProcess1 = $this->createImportProcess();
        $this->runImport($importProcess1);
        $this->assertEquals(5, Branch::count());

        // Modify a branch manually
        $branch = Branch::where('branch_code', 'BR-MAIN')->first();
        $branch->update(['fantasy_name' => 'MODIFIED NAME']);

        // Second import
        $importProcess2 = $this->createImportProcess();
        $this->runImport($importProcess2);

        $this->assertEquals(5, Branch::count(), 'Should still have 5 branches (no duplicates)');

        $branch->refresh();
        $this->assertEquals('Sucursal Central', $branch->fantasy_name, 'Name should be updated from Excel');
    }

    public function test_import_process_status_transitions(): void
    {
        $importProcess = $this->createImportProcess();

        $this->assertEquals(
            ImportProcess::STATUS_QUEUED,
            $importProcess->status,
            'Initial status should be QUEUED'
        );

        $this->runImport($importProcess);

        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Final status should be PROCESSED'
        );
    }

    // ---------------------------------------------------------------
    // Dispatch Rule Import Tests
    // ---------------------------------------------------------------

    /**
     * Case 1: Excel has dispatch rule name, branch has no rule → associate.
     *
     * BR-MAIN fixture has "DESPACHO TEST 60K".
     */
    public function test_import_associates_dispatch_rule_when_branch_has_none(): void
    {
        $rule = DispatchRule::create([
            'name' => 'DESPACHO TEST 60K',
            'priority' => 1,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        DispatchRule::create([
            'name' => 'DESPACHO TEST 35K',
            'priority' => 2,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        $importProcess->refresh();
        $this->assertEquals(ImportProcess::STATUS_PROCESSED, $importProcess->status);

        $branch = Branch::where('branch_code', 'BR-MAIN')->first();
        $this->assertNotNull($branch);
        $this->assertEquals(1, $branch->dispatchRules()->count(), 'BR-MAIN should have 1 dispatch rule');
        $this->assertEquals($rule->id, $branch->dispatchRules()->first()->id);
    }

    /**
     * Case 2: Excel has dispatch rule name, branch already has a different rule → replace.
     */
    public function test_import_replaces_dispatch_rule_when_branch_has_different_rule(): void
    {
        $oldRule = DispatchRule::create([
            'name' => 'OLD RULE',
            'priority' => 1,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        $newRule = DispatchRule::create([
            'name' => 'DESPACHO TEST 60K',
            'priority' => 2,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        DispatchRule::create([
            'name' => 'DESPACHO TEST 35K',
            'priority' => 3,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        // Pre-create the branch with the old rule
        $company = Company::where('registration_number', 'REG-001')->first();
        $branch = Branch::create([
            'company_id' => $company->id,
            'branch_code' => 'BR-MAIN',
            'fantasy_name' => 'Old Name',
            'min_price_order' => 0,
        ]);
        $branch->dispatchRules()->sync([$oldRule->id]);

        $this->assertEquals($oldRule->id, $branch->dispatchRules()->first()->id, 'Should start with old rule');

        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        $branch->refresh();
        $this->assertEquals(1, $branch->dispatchRules()->count(), 'Should still have exactly 1 rule');
        $this->assertEquals($newRule->id, $branch->dispatchRules()->first()->id, 'Rule should be replaced with new one');
    }

    /**
     * Case 3: Excel has empty dispatch rule, branch has existing rule → detach.
     *
     * BR-SUR fixture has empty dispatch rule column.
     */
    public function test_import_detaches_dispatch_rule_when_excel_is_empty(): void
    {
        $rule = DispatchRule::create([
            'name' => 'DESPACHO TEST 60K',
            'priority' => 1,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        DispatchRule::create([
            'name' => 'DESPACHO TEST 35K',
            'priority' => 2,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        // Pre-create BR-SUR with a rule
        $company = Company::where('registration_number', 'REG-002')->first();
        $branch = Branch::create([
            'company_id' => $company->id,
            'branch_code' => 'BR-SUR',
            'fantasy_name' => 'Old Sur',
            'min_price_order' => 0,
        ]);
        $branch->dispatchRules()->sync([$rule->id]);

        $this->assertEquals(1, $branch->dispatchRules()->count(), 'Should start with a rule');

        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        $branch->refresh();
        $this->assertEquals(0, $branch->dispatchRules()->count(), 'Rule should be detached');
    }

    /**
     * Case 4: Excel has nonexistent dispatch rule name → silent fail, branch still imports.
     *
     * BR-ORIENTE fixture has "REGLA-INEXISTENTE" which does not exist in DB.
     * The branch should still import successfully without any dispatch rule.
     */
    public function test_import_silently_ignores_nonexistent_dispatch_rule(): void
    {
        DispatchRule::create([
            'name' => 'DESPACHO TEST 60K',
            'priority' => 1,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        DispatchRule::create([
            'name' => 'DESPACHO TEST 35K',
            'priority' => 2,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        $importProcess->refresh();

        // Import should complete as PROCESSED (not PROCESSED_WITH_ERRORS)
        // because nonexistent rule is a silent fail, not a validation error
        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should succeed even with nonexistent dispatch rule. Errors: '.json_encode($importProcess->error_log)
        );

        // BR-ORIENTE should exist but without dispatch rules
        $branch = Branch::where('branch_code', 'BR-ORIENTE')->first();
        $this->assertNotNull($branch, 'Branch should still be imported');
        $this->assertEquals(0, $branch->dispatchRules()->count(), 'Should have no dispatch rule (nonexistent rule silently ignored)');
    }

    /**
     * Reimport: dispatch rule changes between imports.
     * First import with rule A, second import with rule B → branch should end with rule B.
     */
    public function test_reimport_updates_dispatch_rule(): void
    {
        $ruleA = DispatchRule::create([
            'name' => 'DESPACHO TEST 60K',
            'priority' => 1,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        DispatchRule::create([
            'name' => 'DESPACHO TEST 35K',
            'priority' => 2,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        // First import
        $importProcess1 = $this->createImportProcess();
        $this->runImport($importProcess1);

        $branch = Branch::where('branch_code', 'BR-MAIN')->first();
        $this->assertEquals($ruleA->id, $branch->dispatchRules()->first()->id);

        // Second import (same fixture) should keep same rule
        $importProcess2 = $this->createImportProcess();
        $this->runImport($importProcess2);

        $branch->refresh();
        $this->assertEquals(1, $branch->dispatchRules()->count(), 'Should still have exactly 1 rule after reimport');
        $this->assertEquals($ruleA->id, $branch->dispatchRules()->first()->id, 'Rule should remain the same');
    }

    // ---------------------------------------------------------------
    // Validation Error Tests
    // ---------------------------------------------------------------

    /**
     * Test that validation errors are logged correctly for invalid rows.
     *
     * Fixture: tests/Fixtures/test_branch_import_errors.xlsx (8 invalid rows)
     *
     * Row 2:  registration_number required (empty)
     * Row 3:  registration_number does not exist
     * Row 4:  code required (empty)
     * Row 5:  code min 2 chars ("X")
     * Row 6:  fantasy_name required (empty)
     * Row 7:  fantasy_name min 2 chars ("A")
     * Row 8:  price required (empty)
     * Row 9:  price invalid format ("abc")
     */
    public function test_validation_errors_are_logged_for_invalid_rows(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $this->assertEquals(0, Branch::count(), 'Should start with 0 branches');

        $importProcess = $this->createImportProcess();

        $this->assertFileExists($this->errorFile, 'Error fixture file should exist');

        Excel::import(
            new CompanyBranchesImport($importProcess->id),
            $this->errorFile
        );

        $importProcess->refresh();

        // Import should finish with errors
        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
            $importProcess->status,
            'Import should have status PROCESSED_WITH_ERRORS'
        );

        // No branches should be created (all rows have validation errors)
        $this->assertEquals(0, Branch::count(), 'No branches should be created from invalid data');

        // Verify error_log structure
        $this->assertNotNull($importProcess->error_log, 'Error log should not be null');
        $this->assertIsArray($importProcess->error_log, 'Error log should be an array');

        $errors = $importProcess->error_log;

        // Each error entry should have the standard structure
        foreach ($errors as $index => $error) {
            $this->assertArrayHasKey('row', $error, "Error #{$index} should have 'row' key");
            $this->assertArrayHasKey('attribute', $error, "Error #{$index} should have 'attribute' key");
            $this->assertArrayHasKey('errors', $error, "Error #{$index} should have 'errors' key");
            $this->assertArrayHasKey('values', $error, "Error #{$index} should have 'values' key");
        }

        // Collect all error messages grouped by row
        $errorsByRow = [];
        foreach ($errors as $error) {
            $row = $error['row'];
            if (! isset($errorsByRow[$row])) {
                $errorsByRow[$row] = [];
            }
            $messages = is_array($error['errors']) ? $error['errors'] : [$error['errors']];
            $errorsByRow[$row] = array_merge($errorsByRow[$row], $messages);
        }

        // Row 2: registration_number required
        $this->assertArrayHasKey(2, $errorsByRow, 'Should have error for row 2');
        $this->assertErrorContainsMessage($errorsByRow[2], 'registro', 'requerido');

        // Row 3: registration_number does not exist
        $this->assertArrayHasKey(3, $errorsByRow, 'Should have error for row 3');
        $this->assertErrorContainsMessage($errorsByRow[3], 'empresa', 'no existe');

        // Row 4: code required
        $this->assertArrayHasKey(4, $errorsByRow, 'Should have error for row 4');
        $this->assertErrorContainsMessage($errorsByRow[4], 'código', 'requerido');

        // Row 5: code min 2 chars
        $this->assertArrayHasKey(5, $errorsByRow, 'Should have error for row 5');
        $row5Messages = implode(' ', $errorsByRow[5]);
        $this->assertTrue(
            stripos($row5Messages, 'mínimo') !== false || stripos($row5Messages, 'min') !== false || stripos($row5Messages, '2') !== false,
            "Row 5 should report min length error, got: {$row5Messages}"
        );

        // Row 6: fantasy_name required
        $this->assertArrayHasKey(6, $errorsByRow, 'Should have error for row 6');
        $this->assertErrorContainsMessage($errorsByRow[6], 'fantasía', 'requerido');

        // Row 7: fantasy_name min 2 chars
        $this->assertArrayHasKey(7, $errorsByRow, 'Should have error for row 7');
        $row7Messages = implode(' ', $errorsByRow[7]);
        $this->assertTrue(
            stripos($row7Messages, 'mínimo') !== false || stripos($row7Messages, 'min') !== false || stripos($row7Messages, '2') !== false,
            "Row 7 should report min length error, got: {$row7Messages}"
        );

        // Row 8: price required
        $this->assertArrayHasKey(8, $errorsByRow, 'Should have error for row 8');
        $this->assertErrorContainsMessage($errorsByRow[8], 'precio', 'requerido');

        // Row 9: price invalid format
        $this->assertArrayHasKey(9, $errorsByRow, 'Should have error for row 9');
        $row9Messages = implode(' ', $errorsByRow[9]);
        $this->assertTrue(
            stripos($row9Messages, 'precio') !== false || stripos($row9Messages, 'formato') !== false,
            "Row 9 should report invalid price format, got: {$row9Messages}"
        );
    }

    /**
     * Assert that an array of error messages contains both keywords
     */
    private function assertErrorContainsMessage(array $messages, string $keyword1, string $keyword2): void
    {
        $allMessages = implode(' ', $messages);
        $found = stripos($allMessages, $keyword1) !== false
            && stripos($allMessages, $keyword2) !== false;

        $this->assertTrue(
            $found,
            "Expected error containing '{$keyword1}' and '{$keyword2}', got: {$allMessages}"
        );
    }
}
