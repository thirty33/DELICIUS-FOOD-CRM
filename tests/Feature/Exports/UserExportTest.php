<?php

namespace Tests\Feature\Exports;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Exports\UserDataExport;
use App\Exports\UserTemplateExport;
use App\Imports\Concerns\UserColumnDefinition;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ExportProcess;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * User Export & Template Test
 *
 * Validates the complete export flow for users and the template download.
 *
 * Column positions are resolved via UserColumnDefinition::cell() so that
 * adding, removing, or reordering columns only requires updating the
 * shared definition — not every assertion in this file.
 */
class UserExportTest extends TestCase
{
    use RefreshDatabase;

    protected Role $roleAdmin;

    protected Role $roleCafe;

    protected Role $roleAgreement;

    protected Permission $permConsolidado;

    protected Permission $permIndividual;

    protected PriceList $priceList;

    protected Company $company;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles and permissions
        $this->roleAdmin = Role::create(['name' => RoleName::ADMIN->value]);
        $this->roleCafe = Role::create(['name' => RoleName::CAFE->value]);
        $this->roleAgreement = Role::create(['name' => RoleName::AGREEMENT->value]);
        $this->permConsolidado = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);
        $this->permIndividual = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // Price list
        $this->priceList = PriceList::create([
            'name' => 'Lista Estándar',
            'description' => 'Lista de precios estándar',
            'min_price_order' => 0,
        ]);

        // Company with price list
        $this->company = Company::create([
            'name' => 'TEST EXPORT COMPANY S.A.',
            'fantasy_name' => 'TEST EXPORT',
            'company_code' => '11.111.111-1',
            'email' => 'export@test.cl',
            'price_list_id' => $this->priceList->id,
        ]);

        // Branch
        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'branch_code' => '11.111.111-1MAIN',
            'fantasy_name' => 'SUCURSAL PRINCIPAL',
            'min_price_order' => 0,
        ]);

        // Full user with all fields
        $this->user = User::create([
            'name' => 'Test Export User',
            'email' => 'export.user@test.cl',
            'nickname' => 'TEST.EXPORT',
            'password' => Hash::make('ExportPass123'),
            'plain_password' => 'ExportPass123',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'allow_late_orders' => true,
            'validate_min_price' => true,
            'validate_subcategory_rules' => false,
            'master_user' => true,
            'allow_weekend_orders' => false,
        ]);
        $this->user->roles()->attach($this->roleCafe->id);
        $this->user->permissions()->attach($this->permConsolidado->id);
    }

    // ---------------------------------------------------------------
    // Template Tests
    // ---------------------------------------------------------------

    public function test_template_headers_match_column_definition(): void
    {
        $filePath = $this->generateTemplate();
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(
            UserColumnDefinition::headers(),
            $this->readHeaderRow($sheet),
            'Template headers should match UserColumnDefinition'
        );

        $this->cleanupTestFile($filePath);
    }

    public function test_template_has_only_header_row(): void
    {
        $filePath = $this->generateTemplate();
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(1, $sheet->getHighestRow(), 'Template should have only 1 row (headers, no data)');

        $this->cleanupTestFile($filePath);
    }

    public function test_template_headers_are_styled(): void
    {
        $filePath = $this->generateTemplate();
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headerCell = $sheet->getCell('A1');

        $this->assertTrue(
            $headerCell->getStyle()->getFont()->getBold(),
            'Template headers should be bold'
        );

        $this->assertEquals(
            'E2EFDA',
            $headerCell->getStyle()->getFill()->getStartColor()->getRGB(),
            'Template headers should have green background (E2EFDA)'
        );

        $this->cleanupTestFile($filePath);
    }

    public function test_template_headers_match_data_export_headers(): void
    {
        $templatePath = $this->generateTemplate();
        $dataPath = $this->generateUserExport(collect([$this->user->id]));

        $templateHeaders = $this->readHeaderRow(
            $this->loadExcelFile($templatePath)->getActiveSheet()
        );
        $dataHeaders = $this->readHeaderRow(
            $this->loadExcelFile($dataPath)->getActiveSheet()
        );

        $this->assertEquals(
            $templateHeaders,
            $dataHeaders,
            'Template headers and data export headers must be identical'
        );

        $this->cleanupTestFile($templatePath);
        $this->cleanupTestFile($dataPath);
    }

    // ---------------------------------------------------------------
    // Data Export Tests
    // ---------------------------------------------------------------

    public function test_export_headers_match_column_definition(): void
    {
        $filePath = $this->generateUserExport(collect([$this->user->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(
            UserColumnDefinition::headers(),
            $this->readHeaderRow($sheet),
            'Export headers should match UserColumnDefinition'
        );

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_user_data_correctly(): void
    {
        $filePath = $this->generateUserExport(collect([$this->user->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $r = 2; // Row 1 = headers, Row 2 = first data row

        $this->assertCellEquals($sheet, 'nombre', $r, 'Test Export User');
        $this->assertCellEquals($sheet, 'correo_electronico', $r, 'export.user@test.cl');
        $this->assertCellEquals($sheet, 'tipo_de_usuario', $r, 'Café');
        $this->assertCellEquals($sheet, 'tipo_de_convenio', $r, 'Consolidado');
        $this->assertCellEquals($sheet, 'codigo_empresa', $r, '11.111.111-1');
        $this->assertCellEquals($sheet, 'empresa', $r, 'TEST EXPORT COMPANY S.A.');
        $this->assertCellEquals($sheet, 'codigo_sucursal', $r, '11.111.111-1MAIN');
        $this->assertCellEquals($sheet, 'nombre_fantasia_sucursal', $r, 'SUCURSAL PRINCIPAL');
        $this->assertCellEquals($sheet, 'lista_de_precio', $r, 'Lista Estándar');
        $this->assertCellEquals($sheet, 'validar_fecha_y_reglas_de_despacho', $r, '1');
        $this->assertCellEquals($sheet, 'validar_precio_minimo', $r, '1');
        $this->assertCellEquals($sheet, 'validar_reglas_de_subcategoria', $r, '0');
        $this->assertCellEquals($sheet, 'usuario_maestro', $r, '1');
        $this->assertCellEquals($sheet, 'pedidos_en_fines_de_semana', $r, '0');
        $this->assertCellEquals($sheet, 'nombre_de_usuario', $r, 'TEST.EXPORT');
        $this->assertCellEquals($sheet, 'contrasena', $r, 'ExportPass123');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_multiple_users(): void
    {
        $user2 = User::create([
            'name' => 'Second Export User',
            'email' => 'second.export@test.cl',
            'nickname' => 'TEST.SECOND',
            'password' => Hash::make('Pass456'),
            'plain_password' => 'Pass456',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'allow_late_orders' => false,
            'validate_min_price' => false,
            'validate_subcategory_rules' => true,
            'master_user' => false,
            'allow_weekend_orders' => true,
        ]);
        $user2->roles()->attach($this->roleAgreement->id);
        $user2->permissions()->attach($this->permIndividual->id);

        $filePath = $this->generateUserExport(collect([$this->user->id, $user2->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(3, $sheet->getHighestRow(), 'Should have 1 header + 2 data rows');

        $col = UserColumnDefinition::columnLetter('nombre');
        $names = [$sheet->getCell("{$col}2")->getValue(), $sheet->getCell("{$col}3")->getValue()];
        $this->assertContains('Test Export User', $names);
        $this->assertContains('Second Export User', $names);

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_user_without_optional_fields(): void
    {
        $companyNoPL = Company::create([
            'name' => 'NO PRICELIST COMPANY',
            'fantasy_name' => 'NO PL',
            'company_code' => '22.222.222-2',
            'email' => 'nopl@test.cl',
        ]);
        $branchNoPL = Branch::create([
            'company_id' => $companyNoPL->id,
            'branch_code' => '22.222.222-2MAIN',
            'fantasy_name' => 'BRANCH SIN PL',
            'min_price_order' => 0,
        ]);

        $minimalUser = User::create([
            'name' => 'Minimal User',
            'nickname' => 'TEST.MINIMAL',
            'password' => Hash::make('MinPass'),
            'plain_password' => 'MinPass',
            'company_id' => $companyNoPL->id,
            'branch_id' => $branchNoPL->id,
            'allow_late_orders' => false,
            'validate_min_price' => false,
            'validate_subcategory_rules' => false,
            'master_user' => false,
            'allow_weekend_orders' => false,
        ]);
        $minimalUser->roles()->attach($this->roleAdmin->id);

        $filePath = $this->generateUserExport(collect([$minimalUser->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $r = 2;

        $this->assertCellEquals($sheet, 'nombre', $r, 'Minimal User');
        $this->assertCellEmpty($sheet, 'correo_electronico', $r, 'Email should be empty');
        $this->assertCellEquals($sheet, 'tipo_de_usuario', $r, 'Admin');
        $this->assertCellEmpty($sheet, 'tipo_de_convenio', $r, 'Permission should be empty');
        $this->assertCellEmpty($sheet, 'lista_de_precio', $r, 'Price list should be empty');

        // All boolean flags should be '0'
        $this->assertCellEquals($sheet, 'validar_fecha_y_reglas_de_despacho', $r, '0');
        $this->assertCellEquals($sheet, 'validar_precio_minimo', $r, '0');
        $this->assertCellEquals($sheet, 'validar_reglas_de_subcategoria', $r, '0');
        $this->assertCellEquals($sheet, 'usuario_maestro', $r, '0');
        $this->assertCellEquals($sheet, 'pedidos_en_fines_de_semana', $r, '0');

        $this->assertCellEquals($sheet, 'nombre_de_usuario', $r, 'TEST.MINIMAL');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_billing_code_when_present(): void
    {
        $this->user->update(['billing_code' => 'FACT-EXPORT-001']);

        $filePath = $this->generateUserExport(collect([$this->user->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Codigo de Facturacion', $headers, 'Headers should include billing code column');

        $colIndex = array_search('Codigo de Facturacion', $headers);
        $colLetter = chr(ord('A') + $colIndex);

        $this->assertEquals('FACT-EXPORT-001', $sheet->getCell("{$colLetter}2")->getValue());

        $this->cleanupTestFile($filePath);
    }

    public function test_template_includes_billing_code_column(): void
    {
        $filePath = $this->generateTemplate();
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Codigo de Facturacion', $headers, 'Template should include billing code column');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_seller_nickname_when_user_has_seller_assigned(): void
    {
        $seller = User::factory()->create([
            'nickname' => 'TEST.SELLER.EXPORT',
            'is_seller' => true,
        ]);

        $this->user->update(['seller_id' => $seller->id]);

        $filePath = $this->generateUserExport(collect([$this->user->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertCellEquals($sheet, 'codigo_vendedor', 2, 'TEST.SELLER.EXPORT');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_empty_seller_when_no_seller_assigned(): void
    {
        $filePath = $this->generateUserExport(collect([$this->user->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertCellEmpty($sheet, 'codigo_vendedor', 2, 'codigo_vendedor should be empty when no seller assigned');

        $this->cleanupTestFile($filePath);
    }

    public function test_export_process_status_transitions(): void
    {
        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_USERS,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $this->assertEquals(ExportProcess::STATUS_QUEUED, $exportProcess->status);

        $export = new UserDataExport(collect([$this->user->id]), $exportProcess->id);
        Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        $exportProcess->refresh();

        $this->assertEquals(ExportProcess::STATUS_PROCESSED, $exportProcess->status);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Assert a cell value by column key (resolves letter automatically).
     */
    private function assertCellEquals($sheet, string $columnKey, int $row, mixed $expected, ?string $message = null): void
    {
        $cell = UserColumnDefinition::cell($columnKey, $row);
        $this->assertEquals(
            $expected,
            $sheet->getCell($cell)->getValue(),
            $message ?? "Cell {$cell} ({$columnKey}) should be '{$expected}'"
        );
    }

    /**
     * Assert a cell is empty by column key.
     */
    private function assertCellEmpty($sheet, string $columnKey, int $row, ?string $message = null): void
    {
        $cell = UserColumnDefinition::cell($columnKey, $row);
        $this->assertEmpty(
            $sheet->getCell($cell)->getValue(),
            $message ?? "Cell {$cell} ({$columnKey}) should be empty"
        );
    }

    protected function generateUserExport(Collection $userIds): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (! is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_USERS,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $fileName = 'test-exports/users-export-'.now()->format('Y-m-d-His').'.xlsx';
        $fullPath = storage_path('app/'.$fileName);

        $export = new UserDataExport($userIds, $exportProcess->id);
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath);

        return $fullPath;
    }

    protected function generateTemplate(): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (! is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $fileName = 'test-exports/users-template-'.now()->format('Y-m-d-His').'.xlsx';
        $fullPath = storage_path('app/'.$fileName);

        $export = new UserTemplateExport;
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath);

        return $fullPath;
    }

    protected function loadExcelFile(string $filePath): Spreadsheet
    {
        $this->assertFileExists($filePath);

        return IOFactory::load($filePath);
    }

    protected function readHeaderRow($sheet): array
    {
        $headers = [];
        $highestColumn = $sheet->getHighestColumn();

        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $value = $sheet->getCell($col.'1')->getValue();
            if ($value) {
                $headers[] = $value;
            }
        }

        return $headers;
    }

    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
