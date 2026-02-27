<?php

namespace Tests\Feature\Exports;

use App\Exports\CompanyBranchesExport;
use App\Imports\Concerns\BranchColumnDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * BranchTemplateExport Test - Validates the branch template Excel file
 *
 * Tests the CompanyBranchesExport class (template only, no data) including:
 * - Correct headers (10 columns matching BranchColumnDefinition)
 * - Header order matches import class
 * - No data rows (headers only)
 * - Header styling (bold font, green background #E2EFDA)
 */
class BranchTemplateExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_has_correct_headers(): void
    {
        $filePath = $this->generateTemplate();
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $actualHeaders = $this->readHeaderRow($sheet);

        $this->assertEquals(BranchColumnDefinition::headers(), $actualHeaders);

        $this->cleanupTestFile($filePath);
    }

    public function test_template_has_only_header_row(): void
    {
        $filePath = $this->generateTemplate();
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(1, $sheet->getHighestRow(), 'Template should have only 1 row (headers)');

        $this->cleanupTestFile($filePath);
    }

    public function test_template_headers_are_styled(): void
    {
        $filePath = $this->generateTemplate();
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $cellStyle = $sheet->getStyle('A1');

        $this->assertTrue(
            $cellStyle->getFont()->getBold(),
            'Headers should be bold'
        );

        $this->assertEquals(
            'E2EFDA',
            $cellStyle->getFill()->getStartColor()->getRGB(),
            'Headers should have green background (E2EFDA)'
        );

        $this->cleanupTestFile($filePath);
    }

    public function test_template_headers_match_data_export_headers(): void
    {
        // Generate template
        $templatePath = $this->generateTemplate();
        $templateSheet = $this->loadExcelFile($templatePath)->getActiveSheet();
        $templateHeaders = $this->readHeaderRow($templateSheet);

        // Generate data export (with empty company list)
        $dataExportPath = $this->generateDataExport();
        $dataSheet = $this->loadExcelFile($dataExportPath)->getActiveSheet();
        $dataHeaders = $this->readHeaderRow($dataSheet);

        $this->assertEquals(
            $templateHeaders,
            $dataHeaders,
            'Template headers must match data export headers exactly'
        );

        $this->cleanupTestFile($templatePath);
        $this->cleanupTestFile($dataExportPath);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    protected function generateTemplate(): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (! is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $fileName = 'test-exports/branch-template-'.now()->format('Y-m-d-His').'.xlsx';
        $fullPath = storage_path('app/'.$fileName);

        $export = new CompanyBranchesExport;
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath);

        return $fullPath;
    }

    protected function generateDataExport(): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (! is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $exportProcess = \App\Models\ExportProcess::create([
            'type' => \App\Models\ExportProcess::TYPE_BRANCHES,
            'status' => \App\Models\ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $fileName = 'test-exports/branch-data-export-'.now()->format('Y-m-d-His').'.xlsx';
        $fullPath = storage_path('app/'.$fileName);

        $export = new \App\Exports\CompanyBranchesDataExport(collect([]), $exportProcess->id);
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
}
