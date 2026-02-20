<?php

namespace Tests\Feature\Imports;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Imports\UserImport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportProcess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * User Import Test
 *
 * Validates the complete import flow for users from an Excel file,
 * covering all role/permission combinations and field configurations.
 *
 * Fixture: tests/Fixtures/test_user_import.xlsx (10 valid users)
 *
 * Test Users:
 *  1. Admin (no permission), with email + nickname, master=1
 *  2. Café Consolidado, nickname only, company 22...
 *  3. Café Consolidado, nickname only, same company as #2, different branch
 *  4. Café Consolidado, different company, weekend=0
 *  5. Convenio Individual, master=1, nickname only
 *  6. Convenio Individual, email + nickname, same company as #5
 *  7. Convenio Consolidado, weekend=0
 *  8. Café Consolidado, master=1, validate_subcategory=0
 *  9. Admin Consolidado, email + nickname, all validate flags=0
 * 10. Convenio Individual, email only (no nickname)
 *
 * Companies (7):
 *  11.111.111-1  -> branches: MAIN, SEC
 *  22.222.222-2  -> branches: CENTRAL, NORTE
 *  33.333.333-3  -> branch: MAIN
 *  44.444.444-4  -> branch: MAIN
 *  55.555.555-5  -> branch: MAIN
 *  66.666.666-6  -> branch: MAIN
 *  77.777.777-7  -> branch: MAIN
 */
class UserImportTest extends TestCase
{
    use ConfiguresImportTests;
    use RefreshDatabase;

    protected string $testFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureImportTest();

        $this->testFile = base_path('tests/Fixtures/test_user_import.xlsx');

        // Create roles
        Role::create(['name' => RoleName::ADMIN->value]);
        Role::create(['name' => RoleName::CAFE->value]);
        Role::create(['name' => RoleName::AGREEMENT->value]);

        // Create permissions
        Permission::create(['name' => PermissionName::CONSOLIDADO->value]);
        Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // Create companies and branches
        $this->createCompaniesAndBranches();
    }

    private function createCompaniesAndBranches(): void
    {
        // Company 1: 11.111.111-1 (2 branches)
        $comp1 = Company::create([
            'name' => 'TEST ADMIN COMPANY S.A.',
            'fantasy_name' => 'TEST ADMIN COMPANY',
            'company_code' => '11.111.111-1',
            'email' => 'comp1@test.cl',
        ]);
        Branch::create([
            'company_id' => $comp1->id,
            'branch_code' => '11.111.111-1MAIN',
            'fantasy_name' => 'TEST ADMIN BRANCH',
            'min_price_order' => 0,
        ]);
        Branch::create([
            'company_id' => $comp1->id,
            'branch_code' => '11.111.111-1SEC',
            'fantasy_name' => 'TEST ADMIN SEC BRANCH',
            'min_price_order' => 0,
        ]);

        // Company 2: 22.222.222-2 (2 branches)
        $comp2 = Company::create([
            'name' => 'TEST CAFE COMPANY SpA',
            'fantasy_name' => 'TEST CAFE COMPANY',
            'company_code' => '22.222.222-2',
            'email' => 'comp2@test.cl',
        ]);
        Branch::create([
            'company_id' => $comp2->id,
            'branch_code' => '22.222.222-2CENTRAL',
            'fantasy_name' => 'TEST CAFE CENTRAL',
            'min_price_order' => 0,
        ]);
        Branch::create([
            'company_id' => $comp2->id,
            'branch_code' => '22.222.222-2NORTE',
            'fantasy_name' => 'TEST CAFE NORTE',
            'min_price_order' => 0,
        ]);

        // Company 3: 33.333.333-3
        $comp3 = Company::create([
            'name' => 'TEST CAFE SUR SpA',
            'fantasy_name' => 'TEST CAFE SUR',
            'company_code' => '33.333.333-3',
            'email' => 'comp3@test.cl',
        ]);
        Branch::create([
            'company_id' => $comp3->id,
            'branch_code' => '33.333.333-3MAIN',
            'fantasy_name' => 'TEST CAFE SUR',
            'min_price_order' => 0,
        ]);

        // Company 4: 44.444.444-4
        $comp4 = Company::create([
            'name' => 'TEST CONVENIO IND LTDA',
            'fantasy_name' => 'CONVENIO TEST IND',
            'company_code' => '44.444.444-4',
            'email' => 'comp4@test.cl',
        ]);
        Branch::create([
            'company_id' => $comp4->id,
            'branch_code' => '44.444.444-4MAIN',
            'fantasy_name' => 'CONVENIO TEST IND',
            'min_price_order' => 0,
        ]);

        // Company 5: 55.555.555-5
        $comp5 = Company::create([
            'name' => 'TEST CONVENIO CONSOL S.A.',
            'fantasy_name' => 'CONVENIO TEST CONSOL',
            'company_code' => '55.555.555-5',
            'email' => 'comp5@test.cl',
        ]);
        Branch::create([
            'company_id' => $comp5->id,
            'branch_code' => '55.555.555-5MAIN',
            'fantasy_name' => 'CONVENIO TEST CONSOL',
            'min_price_order' => 0,
        ]);

        // Company 6: 66.666.666-6
        $comp6 = Company::create([
            'name' => 'TEST CAFE MAESTRO SpA',
            'fantasy_name' => 'TEST CAFE MAESTRO',
            'company_code' => '66.666.666-6',
            'email' => 'comp6@test.cl',
        ]);
        Branch::create([
            'company_id' => $comp6->id,
            'branch_code' => '66.666.666-6MAIN',
            'fantasy_name' => 'TEST CAFE MAESTRO',
            'min_price_order' => 0,
        ]);

        // Company 7: 77.777.777-7
        $comp7 = Company::create([
            'name' => 'TEST SOLO EMAIL S.A.',
            'fantasy_name' => 'CONVENIO TEST SOLO EMAIL',
            'company_code' => '77.777.777-7',
            'email' => 'comp7@test.cl',
        ]);
        Branch::create([
            'company_id' => $comp7->id,
            'branch_code' => '77.777.777-7MAIN',
            'fantasy_name' => 'CONVENIO TEST SOLO EMAIL',
            'min_price_order' => 0,
        ]);
    }

    private function createImportProcess(): ImportProcess
    {
        return ImportProcess::create([
            'type' => ImportProcess::TYPE_USERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);
    }

    private function runImport(ImportProcess $importProcess): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        Excel::import(
            new UserImport($importProcess->id),
            $this->testFile
        );
    }

    // ---------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------

    public function test_imports_all_users_successfully(): void
    {
        $this->assertEquals(0, User::count(), 'Should start with 0 users');

        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors. Errors: '.json_encode($importProcess->error_log)
        );

        $this->assertNull($importProcess->error_log, 'Error log should be null for successful import');

        $this->assertEquals(10, User::count(), 'Should have created 10 users');
    }

    public function test_admin_user_with_email_and_no_permission(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // User #1: Admin, email + nickname, master, no permission
        $user = User::where('nickname', 'TEST.ADMIN')->first();
        $this->assertNotNull($user, 'Admin user should exist');

        $this->assertEquals('Test Admin User', $user->name);
        $this->assertEquals('admin@testcompany.cl', $user->email);
        $this->assertEquals('TEST.ADMIN', $user->nickname);
        $this->assertTrue($user->hasRole(RoleName::ADMIN->value), 'Should have Admin role');
        $this->assertEmpty($user->permissions->toArray(), 'Admin without permission should have no permissions');

        // Boolean flags
        $this->assertTrue((bool) $user->allow_late_orders);
        $this->assertTrue((bool) $user->validate_min_price);
        $this->assertTrue((bool) $user->validate_subcategory_rules);
        $this->assertTrue((bool) $user->master_user);
        $this->assertTrue((bool) $user->allow_weekend_orders);

        // Company and branch
        $this->assertNotNull($user->company_id);
        $this->assertEquals('11.111.111-1', $user->company->company_code);
        $this->assertNotNull($user->branch_id);
        $this->assertEquals('11.111.111-1MAIN', $user->branch->branch_code);
    }

    public function test_cafe_consolidado_users_with_same_company(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // User #2: Café Consolidado, company 22...
        $user2 = User::where('nickname', 'TEST.CAFE.CENTRAL')->first();
        $this->assertNotNull($user2, 'Cafe Central user should exist');
        $this->assertTrue($user2->hasRole(RoleName::CAFE->value));
        $this->assertTrue($user2->hasPermission(PermissionName::CONSOLIDADO->value));
        $this->assertEquals('22.222.222-2', $user2->company->company_code);
        $this->assertEquals('22.222.222-2CENTRAL', $user2->branch->branch_code);

        // User #3: Same company, different branch
        $user3 = User::where('nickname', 'TEST.CAFE.NORTE')->first();
        $this->assertNotNull($user3, 'Cafe Norte user should exist');
        $this->assertTrue($user3->hasRole(RoleName::CAFE->value));
        $this->assertTrue($user3->hasPermission(PermissionName::CONSOLIDADO->value));
        $this->assertEquals('22.222.222-2', $user3->company->company_code);
        $this->assertEquals('22.222.222-2NORTE', $user3->branch->branch_code);

        // Same company, different branch
        $this->assertEquals($user2->company_id, $user3->company_id);
        $this->assertNotEquals($user2->branch_id, $user3->branch_id);
    }

    public function test_cafe_consolidado_weekend_disabled(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // User #4: weekend=0
        $user = User::where('nickname', 'TEST.CAFE.SUR')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(RoleName::CAFE->value));
        $this->assertTrue($user->hasPermission(PermissionName::CONSOLIDADO->value));
        $this->assertFalse((bool) $user->allow_weekend_orders);
        $this->assertFalse((bool) $user->master_user);
    }

    public function test_convenio_individual_master_user(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // User #5: Convenio Individual, master
        $user = User::where('nickname', 'TEST.MAESTRO')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(RoleName::AGREEMENT->value));
        $this->assertTrue($user->hasPermission(PermissionName::INDIVIDUAL->value));
        $this->assertTrue((bool) $user->master_user);
        $this->assertNull($user->email, 'Should have no email');
        $this->assertEquals('44.444.444-4', $user->company->company_code);
    }

    public function test_convenio_individual_with_email_and_nickname(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // User #6: Convenio Individual, email + nickname, same company as #5
        $user = User::where('nickname', 'TEST.CONVENIO.EMAIL')->first();
        $this->assertNotNull($user);
        $this->assertEquals('convenio@testcompany.cl', $user->email);
        $this->assertTrue($user->hasRole(RoleName::AGREEMENT->value));
        $this->assertTrue($user->hasPermission(PermissionName::INDIVIDUAL->value));
        $this->assertFalse((bool) $user->master_user);
        $this->assertEquals('44.444.444-4', $user->company->company_code);
    }

    public function test_convenio_consolidado(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // User #7: Convenio Consolidado, weekend=0
        $user = User::where('nickname', 'TEST.CONVENIO.CONSOL')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(RoleName::AGREEMENT->value));
        $this->assertTrue($user->hasPermission(PermissionName::CONSOLIDADO->value));
        $this->assertFalse((bool) $user->allow_weekend_orders);
        $this->assertEquals('55.555.555-5', $user->company->company_code);
    }

    public function test_cafe_maestro_with_subcategory_validation_disabled(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // User #8: Café Consolidado, master=1, validate_subcategory=0
        $user = User::where('nickname', 'TEST.CAFE.MAESTRO')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(RoleName::CAFE->value));
        $this->assertTrue($user->hasPermission(PermissionName::CONSOLIDADO->value));
        $this->assertTrue((bool) $user->master_user);
        $this->assertFalse((bool) $user->validate_subcategory_rules);
        $this->assertFalse((bool) $user->allow_weekend_orders);
    }

    public function test_admin_consolidado_all_validations_disabled(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // User #9: Admin Consolidado, all validate=0
        $user = User::where('nickname', 'TEST.ADMIN.CONSOL')->first();
        $this->assertNotNull($user);
        $this->assertEquals('admin.consol@testcompany.cl', $user->email);
        $this->assertTrue($user->hasRole(RoleName::ADMIN->value));
        $this->assertTrue($user->hasPermission(PermissionName::CONSOLIDADO->value));
        $this->assertFalse((bool) $user->allow_late_orders);
        $this->assertFalse((bool) $user->validate_min_price);
        $this->assertFalse((bool) $user->validate_subcategory_rules);
        $this->assertFalse((bool) $user->master_user);
        $this->assertTrue((bool) $user->allow_weekend_orders);

        // Same company as user #1, different branch
        $this->assertEquals('11.111.111-1', $user->company->company_code);
        $this->assertEquals('11.111.111-1SEC', $user->branch->branch_code);
    }

    public function test_user_with_email_only_no_nickname(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        // User #10: only email, no nickname
        $user = User::where('email', 'soloemail@testcompany.cl')->first();
        $this->assertNotNull($user, 'User with only email should exist');
        $this->assertEquals('TEST SOLO EMAIL', $user->name);
        $this->assertNull($user->nickname, 'Nickname should be null');
        $this->assertTrue($user->hasRole(RoleName::AGREEMENT->value));
        $this->assertTrue($user->hasPermission(PermissionName::INDIVIDUAL->value));
        $this->assertEquals('77.777.777-7', $user->company->company_code);
    }

    public function test_passwords_are_hashed_and_plain_stored(): void
    {
        $importProcess = $this->createImportProcess();
        $this->runImport($importProcess);

        $user = User::where('nickname', 'TEST.ADMIN')->first();
        $this->assertNotNull($user);

        // plain_password stored
        $this->assertEquals('AdminTest123', $user->plain_password);

        // password is hashed
        $this->assertNotEquals('AdminTest123', $user->password);
        $this->assertTrue(Hash::check('AdminTest123', $user->password));
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

    public function test_reimport_updates_existing_users_not_duplicates(): void
    {
        $importProcess1 = $this->createImportProcess();
        $this->runImport($importProcess1);

        $this->assertEquals(10, User::count(), 'Should have 10 users after first import');

        $userBeforeReimport = User::where('nickname', 'TEST.ADMIN')->first();
        $originalId = $userBeforeReimport->id;

        // Second import
        $importProcess2 = $this->createImportProcess();
        $this->runImport($importProcess2);

        $this->assertEquals(10, User::count(), 'Should still have 10 users after re-import (no duplicates)');

        $userAfterReimport = User::where('nickname', 'TEST.ADMIN')->first();
        $this->assertEquals($originalId, $userAfterReimport->id, 'User ID should remain the same');
    }

    public function test_reimport_does_not_duplicate_roles_and_permissions(): void
    {
        $importProcess1 = $this->createImportProcess();
        $this->runImport($importProcess1);

        $user = User::where('nickname', 'TEST.ADMIN')->first();
        $this->assertEquals(1, $user->roles->count(), 'Should have exactly 1 role');

        // Re-import
        $importProcess2 = $this->createImportProcess();
        $this->runImport($importProcess2);

        $user->refresh();
        $user->load('roles', 'permissions');
        $this->assertEquals(1, $user->roles->count(), 'Should still have exactly 1 role after re-import');
        $this->assertTrue($user->hasRole(RoleName::ADMIN->value));
    }

    // ---------------------------------------------------------------
    // Validation Error Tests
    // ---------------------------------------------------------------

    /**
     * Test that all validation rules generate errors correctly
     *
     * Fixture: tests/Fixtures/test_user_import_errors.xlsx (12 invalid rows)
     *
     * Each row triggers a specific validation error:
     *   Row 2:  nombre required (empty name)
     *   Row 3:  tipo_de_usuario required (empty role)
     *   Row 4:  tipo_de_usuario exists (invalid role name)
     *   Row 5:  tipo_de_convenio exists (invalid permission name)
     *   Row 6:  codigo_empresa required (empty company code)
     *   Row 7:  codigo_empresa exists (nonexistent company code)
     *   Row 8:  codigo_sucursal required (empty branch code)
     *   Row 9:  codigo_sucursal exists (nonexistent branch code)
     *   Row 10: contrasena required (empty password)
     *   Row 11: boolean field invalid value ("INVALIDO")
     *   Row 12: neither email nor nickname (withValidator)
     *   Row 13: invalid email format (withValidator)
     */
    public function test_validation_errors_are_logged_for_invalid_rows(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $this->assertEquals(0, User::count(), 'Should start with 0 users');

        $importProcess = $this->createImportProcess();

        $errorFile = base_path('tests/Fixtures/test_user_import_errors.xlsx');
        $this->assertFileExists($errorFile, 'Error fixture file should exist');

        Excel::import(
            new UserImport($importProcess->id),
            $errorFile
        );

        $importProcess->refresh();

        // Import should finish with errors
        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
            $importProcess->status,
            'Import should have status PROCESSED_WITH_ERRORS'
        );

        // No users should be created (all rows have validation errors)
        $this->assertEquals(0, User::count(), 'No users should be created from invalid data');

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

        // Collect all error messages grouped by row for assertions
        $errorsByRow = [];
        foreach ($errors as $error) {
            $row = $error['row'];
            if (! isset($errorsByRow[$row])) {
                $errorsByRow[$row] = [];
            }
            $messages = is_array($error['errors']) ? $error['errors'] : [$error['errors']];
            $errorsByRow[$row] = array_merge($errorsByRow[$row], $messages);
        }

        // Row 2: nombre required
        $this->assertArrayHasKey(2, $errorsByRow, 'Should have error for row 2 (nombre required)');
        $this->assertErrorContainsMessage($errorsByRow[2], 'nombre', 'obligatorio');

        // Row 3: tipo_de_usuario required
        $this->assertArrayHasKey(3, $errorsByRow, 'Should have error for row 3 (tipo_de_usuario required)');
        $this->assertErrorContainsMessage($errorsByRow[3], 'tipo de usuario', 'obligatorio');

        // Row 4: tipo_de_usuario exists (invalid role)
        $this->assertArrayHasKey(4, $errorsByRow, 'Should have error for row 4 (tipo_de_usuario exists)');
        $this->assertErrorContainsMessage($errorsByRow[4], 'tipo de usuario', 'no existe');

        // Row 5: tipo_de_convenio exists (invalid permission)
        $this->assertArrayHasKey(5, $errorsByRow, 'Should have error for row 5 (tipo_de_convenio exists)');
        $this->assertErrorContainsMessage($errorsByRow[5], 'tipo de convenio', 'no existe');

        // Row 6: codigo_empresa required
        $this->assertArrayHasKey(6, $errorsByRow, 'Should have error for row 6 (codigo_empresa required)');
        $this->assertErrorContainsMessage($errorsByRow[6], 'empresa', 'obligatorio');

        // Row 7: codigo_empresa exists (nonexistent company)
        $this->assertArrayHasKey(7, $errorsByRow, 'Should have error for row 7 (codigo_empresa exists)');
        $this->assertErrorContainsMessage($errorsByRow[7], 'empresa', 'no existe');

        // Row 8: codigo_sucursal required
        $this->assertArrayHasKey(8, $errorsByRow, 'Should have error for row 8 (codigo_sucursal required)');
        $this->assertErrorContainsMessage($errorsByRow[8], 'sucursal', 'obligatorio');

        // Row 9: codigo_sucursal exists (nonexistent branch)
        $this->assertArrayHasKey(9, $errorsByRow, 'Should have error for row 9 (codigo_sucursal exists)');
        $this->assertErrorContainsMessage($errorsByRow[9], 'sucursal', 'no existe');

        // Row 10: contrasena required
        $this->assertArrayHasKey(10, $errorsByRow, 'Should have error for row 10 (contrasena required)');
        $this->assertErrorContainsMessage($errorsByRow[10], 'contrase', 'obligatoria');

        // Row 11: boolean field invalid value
        $this->assertArrayHasKey(11, $errorsByRow, 'Should have error for row 11 (invalid boolean)');
        $row11Messages = implode(' ', $errorsByRow[11]);
        $this->assertMatchesRegularExpression(
            '/validar.*(fecha|reglas|despacho)/i',
            $row11Messages,
            'Row 11 should report invalid boolean for validar_fecha_y_reglas_de_despacho'
        );

        // Row 12: neither email nor nickname
        $this->assertArrayHasKey(12, $errorsByRow, 'Should have error for row 12 (no email nor nickname)');
        $row12Messages = implode(' ', $errorsByRow[12]);
        $this->assertMatchesRegularExpression(
            '/correo.*usuario|usuario.*correo/i',
            $row12Messages,
            'Row 12 should report that either email or nickname is required'
        );

        // Row 13: invalid email format
        $this->assertArrayHasKey(13, $errorsByRow, 'Should have error for row 13 (invalid email)');
        $row13Messages = implode(' ', $errorsByRow[13]);
        $this->assertStringContainsString(
            'formato',
            mb_strtolower($row13Messages),
            'Row 13 should report invalid email format'
        );
    }

    /**
     * Test that "CODIGO DE FACTURACION" column is imported into billing_code field.
     *
     * Fixture: tests/Fixtures/test_user_import_billing_code.xlsx (3 users)
     *   User 1: billing code = "FACT-001"
     *   User 2: billing code = "FACT-002"
     *   User 3: billing code = empty
     */
    public function test_imports_billing_code_when_present(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = $this->createImportProcess();

        $billingFile = base_path('tests/Fixtures/test_user_import_billing_code.xlsx');
        $this->assertFileExists($billingFile);

        Excel::import(
            new UserImport($importProcess->id),
            $billingFile
        );

        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors. Errors: '.json_encode($importProcess->error_log)
        );

        $this->assertEquals(3, User::count(), 'Should have created 3 users');

        // User 1: has billing code
        $user1 = User::where('nickname', 'TEST.BILLING1')->first();
        $this->assertNotNull($user1);
        $this->assertEquals('FACT-001', $user1->billing_code);

        // User 2: has billing code
        $user2 = User::where('nickname', 'TEST.BILLING2')->first();
        $this->assertNotNull($user2);
        $this->assertEquals('FACT-002', $user2->billing_code);

        // User 3: no billing code
        $user3 = User::where('nickname', 'TEST.NOBILLING')->first();
        $this->assertNotNull($user3);
        $this->assertNull($user3->billing_code);
    }

    public function test_imports_user_with_seller_assigned(): void
    {
        $seller = User::factory()->create([
            'nickname' => 'TEST.SELLER.IMPORT',
            'is_seller' => true,
        ]);

        $tempFile = $this->createSellerImportFixture('TEST.SELLER.IMPORT');

        $importProcess = $this->createImportProcess();

        \Illuminate\Support\Facades\Storage::fake('s3');

        Excel::import(new UserImport($importProcess->id), $tempFile);

        unlink($tempFile);

        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors. Errors: '.json_encode($importProcess->error_log)
        );

        $user = User::where('nickname', 'TEST.WITH.SELLER')->first();
        $this->assertNotNull($user, 'Imported user should exist');
        $this->assertEquals($seller->id, $user->seller_id, 'Imported user should be assigned to the seller');
    }

    private function createSellerImportFixture(string $sellerNickname): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = array_values(\App\Imports\Concerns\UserColumnDefinition::COLUMNS);

        foreach ($headers as $index => $header) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($col.'1', $header);
        }

        $row = [
            'Test With Seller',
            '',
            RoleName::AGREEMENT->value,
            PermissionName::INDIVIDUAL->value,
            '11.111.111-1',
            '',
            '11.111.111-1MAIN',
            '',
            '',
            '1',
            '1',
            '1',
            '0',
            '1',
            'TEST.WITH.SELLER',
            'Password123',
            '',
            $sellerNickname,
        ];

        foreach ($row as $index => $value) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($col.'2', $value);
        }

        $tempPath = sys_get_temp_dir().'/test_seller_import_'.uniqid().'.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    /**
     * Assert that an array of error messages contains a message matching both keywords
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
