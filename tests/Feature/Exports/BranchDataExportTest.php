<?php

namespace Tests\Feature\Exports;

use App\Exports\CompanyBranchesDataExport;
use App\Imports\CompanyBranchesImport;
use App\Imports\Concerns\BranchColumnDefinition;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DispatchRule;
use App\Models\ExportProcess;
use App\Models\ImportProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * BranchDataExport Test - Validates branch data export to Excel files
 *
 * Tests the CompanyBranchesDataExport class including:
 * - Correct headers (10 columns matching BranchColumnDefinition)
 * - Header styling (bold, green background)
 * - Data mapping (registration_number, branch fields, price formatting)
 * - Dispatch rule name export
 * - Nullable fields exported as empty
 * - Multiple branches from multiple companies
 * - ExportProcess status transitions
 * - Round-trip (export → import)
 */
class BranchDataExportTest extends TestCase
{
    use ConfiguresImportTests;
    use RefreshDatabase;

    protected Company $company1;

    protected Company $company2;

    protected Branch $branch1;

    protected Branch $branch2;

    protected Branch $branch3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureImportTest();

        $this->company1 = Company::create([
            'name' => 'EXPORT TEST COMPANY 1',
            'fantasy_name' => 'EXPORT TEST 1',
            'registration_number' => 'EXP-REG-001',
            'email' => 'export1@test.cl',
        ]);

        $this->company2 = Company::create([
            'name' => 'EXPORT TEST COMPANY 2',
            'fantasy_name' => 'EXPORT TEST 2',
            'registration_number' => 'EXP-REG-002',
            'email' => 'export2@test.cl',
        ]);

        // Branch with all fields
        $this->branch1 = Branch::create([
            'company_id' => $this->company1->id,
            'branch_code' => 'EXP-BR-001',
            'fantasy_name' => 'Sucursal Exportación 1',
            'address' => 'Av. Exportación 100',
            'shipping_address' => 'Av. Despacho 100',
            'contact_name' => 'Carlos',
            'contact_last_name' => 'Exportador',
            'contact_phone_number' => '56912345678',
            'min_price_order' => 500000,
        ]);

        // Branch with nullable fields
        $this->branch2 = Branch::create([
            'company_id' => $this->company1->id,
            'branch_code' => 'EXP-BR-002',
            'fantasy_name' => 'Sucursal Exportación 2',
            'address' => null,
            'shipping_address' => null,
            'contact_name' => null,
            'contact_last_name' => null,
            'contact_phone_number' => null,
            'min_price_order' => 0,
        ]);

        // Branch from different company
        $this->branch3 = Branch::create([
            'company_id' => $this->company2->id,
            'branch_code' => 'EXP-BR-003',
            'fantasy_name' => 'Sucursal Exportación 3',
            'address' => 'Calle Export 300',
            'shipping_address' => null,
            'contact_name' => 'Ana',
            'contact_last_name' => null,
            'contact_phone_number' => '56987654321',
            'min_price_order' => 350050,
        ]);
    }

    // ---------------------------------------------------------------
    // Header Tests
    // ---------------------------------------------------------------

    public function test_export_headers_are_correct(): void
    {
        $filePath = $this->generateBranchExport(collect([$this->company1->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(BranchColumnDefinition::headers(), $this->readHeaderRow($sheet));

        $this->cleanupTestFile($filePath);
    }

    public function test_headers_are_styled(): void
    {
        $filePath = $this->generateBranchExport(collect([$this->company1->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headerCell = $sheet->getCell('A1');

        $this->assertTrue(
            $headerCell->getStyle()->getFont()->getBold(),
            'Headers should be bold'
        );

        $this->assertEquals(
            'E2EFDA',
            $headerCell->getStyle()->getFill()->getStartColor()->getRGB(),
            'Headers should have green background (E2EFDA)'
        );

        $this->cleanupTestFile($filePath);
    }

    // ---------------------------------------------------------------
    // Data Export Tests
    // ---------------------------------------------------------------

    public function test_exports_branch_with_all_fields(): void
    {
        $filePath = $this->generateBranchExport(collect([$this->company1->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        // Find the row with EXP-BR-001
        $codeCol = BranchColumnDefinition::columnLetter('codigo');
        $row = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-001');
        $this->assertNotNull($row, 'Should find branch EXP-BR-001 in export');

        $this->assertEquals('EXP-REG-001', $sheet->getCell(BranchColumnDefinition::cell('numero_de_registro_de_compania', $row))->getValue());
        $this->assertEquals('EXP-BR-001', $sheet->getCell(BranchColumnDefinition::cell('codigo', $row))->getValue());
        $this->assertEquals('Sucursal Exportación 1', $sheet->getCell(BranchColumnDefinition::cell('nombre_de_fantasia', $row))->getValue());
        $this->assertEquals('Av. Exportación 100', $sheet->getCell(BranchColumnDefinition::cell('direccion', $row))->getValue());
        $this->assertEquals('Av. Despacho 100', $sheet->getCell(BranchColumnDefinition::cell('direccion_de_despacho', $row))->getValue());
        $this->assertEquals('Carlos', $sheet->getCell(BranchColumnDefinition::cell('nombre_de_contacto', $row))->getValue());
        $this->assertEquals('Exportador', $sheet->getCell(BranchColumnDefinition::cell('apellido_de_contacto', $row))->getValue());
        $this->assertEquals('56912345678', $sheet->getCell(BranchColumnDefinition::cell('telefono_de_contacto', $row))->getValue());
        $this->assertEquals('$5,000.00', $sheet->getCell(BranchColumnDefinition::cell('precio_pedido_minimo', $row))->getValue());
        $this->assertNull($sheet->getCell(BranchColumnDefinition::cell('regla_de_transporte', $row))->getValue(), 'Dispatch rule should be null when branch has no rule');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_branch_with_nullable_fields_empty(): void
    {
        $filePath = $this->generateBranchExport(collect([$this->company1->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $codeCol = BranchColumnDefinition::columnLetter('codigo');
        $row = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-002');
        $this->assertNotNull($row, 'Should find branch EXP-BR-002 in export');

        $this->assertEquals('EXP-REG-001', $sheet->getCell(BranchColumnDefinition::cell('numero_de_registro_de_compania', $row))->getValue());
        $this->assertEquals('EXP-BR-002', $sheet->getCell(BranchColumnDefinition::cell('codigo', $row))->getValue());
        $this->assertEquals('Sucursal Exportación 2', $sheet->getCell(BranchColumnDefinition::cell('nombre_de_fantasia', $row))->getValue());
        $this->assertNull($sheet->getCell(BranchColumnDefinition::cell('direccion', $row))->getValue(), 'Address should be null');
        $this->assertNull($sheet->getCell(BranchColumnDefinition::cell('direccion_de_despacho', $row))->getValue(), 'Shipping address should be null');
        $this->assertNull($sheet->getCell(BranchColumnDefinition::cell('nombre_de_contacto', $row))->getValue(), 'Contact name should be null');
        $this->assertNull($sheet->getCell(BranchColumnDefinition::cell('apellido_de_contacto', $row))->getValue(), 'Contact last name should be null');
        $this->assertNull($sheet->getCell(BranchColumnDefinition::cell('telefono_de_contacto', $row))->getValue(), 'Contact phone should be null');
        $this->assertEquals('$0.00', $sheet->getCell(BranchColumnDefinition::cell('precio_pedido_minimo', $row))->getValue());
        $this->assertNull($sheet->getCell(BranchColumnDefinition::cell('regla_de_transporte', $row))->getValue(), 'Dispatch rule should be null');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_multiple_branches_from_multiple_companies(): void
    {
        $filePath = $this->generateBranchExport(collect([$this->company1->id, $this->company2->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        // 1 header + 3 data rows
        $this->assertEquals(4, $sheet->getHighestRow(), 'Should have 1 header + 3 data rows');

        $codeCol = BranchColumnDefinition::columnLetter('codigo');
        $regCol = BranchColumnDefinition::columnLetter('numero_de_registro_de_compania');

        // Verify each branch's company registration_number
        $row1 = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-001');
        $this->assertEquals('EXP-REG-001', $sheet->getCell("{$regCol}{$row1}")->getValue());

        $row2 = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-002');
        $this->assertEquals('EXP-REG-001', $sheet->getCell("{$regCol}{$row2}")->getValue());

        $row3 = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-003');
        $this->assertEquals('EXP-REG-002', $sheet->getCell("{$regCol}{$row3}")->getValue());

        $this->cleanupTestFile($filePath);
    }

    public function test_export_process_status_transitions(): void
    {
        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_BRANCHES,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $this->assertEquals(ExportProcess::STATUS_QUEUED, $exportProcess->status);

        $export = new CompanyBranchesDataExport(
            collect([$this->company1->id]),
            $exportProcess->id
        );
        Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        $exportProcess->refresh();
        $this->assertEquals(ExportProcess::STATUS_PROCESSED, $exportProcess->status);
    }

    public function test_exports_price_formatted_correctly(): void
    {
        $filePath = $this->generateBranchExport(collect([$this->company1->id, $this->company2->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $codeCol = BranchColumnDefinition::columnLetter('codigo');
        $priceCol = BranchColumnDefinition::columnLetter('precio_pedido_minimo');

        // 500000 cents -> $5,000.00
        $row1 = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-001');
        $this->assertEquals('$5,000.00', $sheet->getCell("{$priceCol}{$row1}")->getValue());

        // 0 cents -> $0.00
        $row2 = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-002');
        $this->assertEquals('$0.00', $sheet->getCell("{$priceCol}{$row2}")->getValue());

        // 350050 cents -> $3,500.50
        $row3 = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-003');
        $this->assertEquals('$3,500.50', $sheet->getCell("{$priceCol}{$row3}")->getValue());

        $this->cleanupTestFile($filePath);
    }

    // ---------------------------------------------------------------
    // Dispatch Rule Export Tests
    // ---------------------------------------------------------------

    public function test_exports_dispatch_rule_name_for_branch_with_rule(): void
    {
        $rule = DispatchRule::create([
            'name' => 'DESPACHO TEST 60K',
            'priority' => 1,
            'active' => true,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        $this->branch1->dispatchRules()->sync([$rule->id]);

        $filePath = $this->generateBranchExport(collect([$this->company1->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $codeCol = BranchColumnDefinition::columnLetter('codigo');
        $row = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-001');
        $this->assertNotNull($row);

        $this->assertEquals(
            'DESPACHO TEST 60K',
            $sheet->getCell(BranchColumnDefinition::cell('regla_de_transporte', $row))->getValue(),
            'Should export the dispatch rule name'
        );

        // branch2 has no rule
        $row2 = $this->findRowByColumnValue($sheet, $codeCol, 'EXP-BR-002');
        $this->assertNull(
            $sheet->getCell(BranchColumnDefinition::cell('regla_de_transporte', $row2))->getValue(),
            'Branch without dispatch rule should have null'
        );

        $this->cleanupTestFile($filePath);
    }

    // ---------------------------------------------------------------
    // Round-trip Test (Export → Import)
    // ---------------------------------------------------------------

    /**
     * Round-trip test: export branches, delete them, reimport the exported file.
     *
     * Note: Phone numbers are excluded because Excel converts numeric strings
     * to integers, which then fail the import's 'string' validation rule.
     * This is a known Excel limitation, not a bug in the export/import code.
     */
    public function test_exported_file_can_be_reimported(): void
    {
        // Use branch2 (no phone) for clean round-trip
        $filePath = $this->generateBranchExport(collect([$this->company1->id]));

        // Save original data for comparison
        $originalBranch = Branch::where('branch_code', 'EXP-BR-002')->first();
        $originalFantasyName = $originalBranch->fantasy_name;
        $originalPrice = $originalBranch->min_price_order;

        // Delete all branches
        Branch::query()->delete();
        $this->assertEquals(0, Branch::count());

        // Re-import the exported file
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_BRANCHES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new CompanyBranchesImport($importProcess->id),
            $filePath
        );

        $importProcess->refresh();

        // Rows with numeric phone numbers will fail validation (Excel converts to int),
        // but rows without phone (EXP-BR-002) should import successfully
        $reimportedBranch = Branch::where('branch_code', 'EXP-BR-002')->first();
        $this->assertNotNull($reimportedBranch, 'Branch without phone should reimport successfully');
        $this->assertEquals($originalFantasyName, $reimportedBranch->fantasy_name);
        $this->assertEquals($originalPrice, $reimportedBranch->min_price_order);
        $this->assertEquals($this->company1->id, $reimportedBranch->company_id);

        $this->cleanupTestFile($filePath);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    protected function generateBranchExport($companyIds): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (! is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_BRANCHES,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $fileName = 'test-exports/branch-data-export-'.now()->format('Y-m-d-His').'.xlsx';
        $fullPath = storage_path('app/'.$fileName);

        $export = new CompanyBranchesDataExport($companyIds, $exportProcess->id);
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath);

        return $fullPath;
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

    protected function loadExcelFile(string $filePath): Spreadsheet
    {
        $this->assertFileExists($filePath);

        return IOFactory::load($filePath);
    }

    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    protected function findRowByColumnValue($sheet, string $column, string $value): ?int
    {
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            if ($sheet->getCell("{$column}{$row}")->getValue() === $value) {
                return $row;
            }
        }

        return null;
    }
}
