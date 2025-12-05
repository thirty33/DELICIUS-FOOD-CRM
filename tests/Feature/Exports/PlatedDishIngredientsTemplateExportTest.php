<?php

namespace Tests\Feature\Exports;

use App\Exports\PlatedDishIngredientsTemplateExport;
use App\Support\ImportExport\PlatedDishIngredientsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * PlatedDishIngredientsTemplateExport Test
 *
 * Validates that the template Excel file:
 * 1. Is generated successfully
 * 2. Has exactly 6 headers matching import expectations
 * 3. Has headers in the correct order
 * 4. Has only headers (no data rows)
 * 5. Has proper styling (bold, green background)
 *
 * This template is used by end-users to import plated dish ingredients data.
 *
 * TDD RED PHASE:
 * This test will FAIL because PlatedDishIngredientsTemplateExport class does not exist yet.
 */
class PlatedDishIngredientsTemplateExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that template file is generated successfully
     *
     * TDD RED PHASE: This will FAIL because PlatedDishIngredientsTemplateExport does not exist
     */
    public function test_template_file_is_generated_successfully(): void
    {
        // Generate template file
        $filePath = $this->generateTemplate();

        // Verify file exists
        $this->assertFileExists($filePath, 'Template file should be created');

        // Verify file is not empty
        $this->assertGreaterThan(0, filesize($filePath), 'Template file should not be empty');

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that template has exactly 7 headers in correct order
     */
    public function test_template_has_7_headers_in_correct_order(): void
    {
        // Expected headers from PlatedDishIngredientsSchema (centralized)
        $expectedHeaders = PlatedDishIngredientsSchema::getHeaderValues();

        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Read headers from row 1
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert we have expected number of headers
        $expectedCount = PlatedDishIngredientsSchema::getHeaderCount();
        $this->assertCount($expectedCount, $actualHeaders, "Template should have exactly {$expectedCount} headers");

        // Assert headers match exactly in correct order
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Template headers MUST match PlatedDishIngredientsImport expectations EXACTLY in the same order'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that template includes shelf_life header "VIDA UTIL" as 7th column
     *
     * This test validates that the template export includes the new "VIDA UTIL" column
     * to match the updated import expectations (7 columns instead of 6).
     */
    public function test_template_includes_vida_util_header(): void
    {
        // Expected headers from PlatedDishIngredientsSchema (centralized)
        $expectedHeaders = PlatedDishIngredientsSchema::getHeaderValues();

        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Read headers from row 1
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert we have expected number of headers
        $expectedCount = PlatedDishIngredientsSchema::getHeaderCount();
        $this->assertCount($expectedCount, $actualHeaders, "Template should have exactly {$expectedCount} headers including VIDA UTIL");

        // Assert headers match exactly in correct order
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Template headers MUST include VIDA UTIL as 7th column'
        );

        // Verify "VIDA UTIL" is in Column G (7th column)
        $vidaUtilHeader = $sheet->getCell('G1')->getValue();
        $this->assertEquals(
            'VIDA UTIL',
            $vidaUtilHeader,
            'Column G should contain "VIDA UTIL" header'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that template contains only headers (no data rows)
     */
    public function test_template_contains_only_headers_no_data(): void
    {
        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Get highest row number
        $highestRow = $sheet->getHighestRow();

        // Assert only 1 row exists (headers only)
        $this->assertEquals(1, $highestRow, 'Template should have only 1 row (headers), no data rows');

        // Verify row 2 is empty
        $row2HasData = false;
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellValue = $sheet->getCellByColumnAndRow($col, 2)->getValue();
            if ($cellValue !== null && $cellValue !== '') {
                $row2HasData = true;
                break;
            }
        }

        $this->assertFalse($row2HasData, 'Row 2 should be empty (template should have no data rows)');

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that header row has proper styling (bold font, green background)
     */
    public function test_header_row_has_proper_styling(): void
    {
        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Get style for cell A1 (first header cell)
        $cellStyle = $sheet->getStyle('A1');

        // Assert font is bold
        $this->assertTrue(
            $cellStyle->getFont()->getBold(),
            'Header row should have bold font'
        );

        // Assert background fill color is green (#E2EFDA)
        $fillColor = $cellStyle->getFill()->getStartColor()->getRGB();
        $this->assertEquals(
            'E2EFDA',
            $fillColor,
            'Header row should have green background color (#E2EFDA)'
        );

        // Assert fill type is solid
        $fillType = $cellStyle->getFill()->getFillType();
        $this->assertEquals(
            \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            $fillType,
            'Header row should have solid fill type'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that template headers match import class expected headers
     */
    public function test_template_headers_match_import_class_expectations(): void
    {
        // Get expected headers from import class
        $repository = app(\App\Contracts\PlatedDishRepositoryInterface::class);
        $importClass = new \App\Imports\PlatedDishIngredientsImport($repository, 1);
        $expectedHeadersFromImport = $importClass->getExpectedHeaders();

        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Read actual headers from template
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert template headers match import expectations
        $this->assertEquals(
            $expectedHeadersFromImport,
            $actualHeaders,
            'Template headers MUST match PlatedDishIngredientsImport::getExpectedHeaders() EXACTLY'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that all header cells have consistent styling
     */
    public function test_all_header_cells_have_consistent_styling(): void
    {
        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify all 6 header cells have the same styling
        for ($col = 1; $col <= 6; $col++) {
            $cellStyle = $sheet->getCellByColumnAndRow($col, 1)->getStyle();

            // Assert font is bold
            $this->assertTrue(
                $cellStyle->getFont()->getBold(),
                "Header cell in column {$col} should have bold font"
            );

            // Assert background fill color is green
            $fillColor = $cellStyle->getFill()->getStartColor()->getRGB();
            $this->assertEquals(
                'E2EFDA',
                $fillColor,
                "Header cell in column {$col} should have green background"
            );

            // Assert fill type is solid
            $fillType = $cellStyle->getFill()->getFillType();
            $this->assertEquals(
                \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                $fillType,
                "Header cell in column {$col} should have solid fill type"
            );
        }

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that VIDA UTIL header cell has proper styling
     *
     * This test validates that the 7th column "VIDA UTIL" has the same styling
     * as the other 6 headers (bold font, green background).
     */
    public function test_vida_util_header_has_proper_styling(): void
    {
        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify all 7 header cells (including VIDA UTIL) have the same styling
        for ($col = 1; $col <= 7; $col++) {
            $cellStyle = $sheet->getCellByColumnAndRow($col, 1)->getStyle();
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);

            // Assert font is bold
            $this->assertTrue(
                $cellStyle->getFont()->getBold(),
                "Header cell {$columnLetter}1 (column {$col}) should have bold font"
            );

            // Assert background fill color is green (#E2EFDA)
            $fillColor = $cellStyle->getFill()->getStartColor()->getRGB();
            $this->assertEquals(
                'E2EFDA',
                $fillColor,
                "Header cell {$columnLetter}1 (column {$col}) should have green background (#E2EFDA)"
            );

            // Assert fill type is solid
            $fillType = $cellStyle->getFill()->getFillType();
            $this->assertEquals(
                \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                $fillType,
                "Header cell {$columnLetter}1 (column {$col}) should have solid fill type"
            );
        }

        // Specifically verify Column G (VIDA UTIL) has correct styling
        $vidaUtilStyle = $sheet->getStyle('G1');

        $this->assertTrue(
            $vidaUtilStyle->getFont()->getBold(),
            'VIDA UTIL header (G1) should have bold font'
        );

        $this->assertEquals(
            'E2EFDA',
            $vidaUtilStyle->getFill()->getStartColor()->getRGB(),
            'VIDA UTIL header (G1) should have green background (#E2EFDA)'
        );

        $this->assertEquals(
            \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            $vidaUtilStyle->getFill()->getFillType(),
            'VIDA UTIL header (G1) should have solid fill type'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that template includes es_horeca header "ES HORECA" as 8th column
     *
     * This test validates that the template export includes the new "ES HORECA" column
     * to match the updated import expectations (8 columns instead of 7).
     */
    public function test_template_includes_es_horeca_header(): void
    {
        // Expected headers from PlatedDishIngredientsSchema (centralized)
        $expectedHeaders = PlatedDishIngredientsSchema::getHeaderValues();

        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Read headers from row 1
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert we have expected number of headers
        $expectedCount = PlatedDishIngredientsSchema::getHeaderCount();
        $this->assertCount($expectedCount, $actualHeaders, "Template should have exactly {$expectedCount} headers including ES HORECA");

        // Assert headers match exactly in correct order
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Template headers MUST include ES HORECA as 8th column'
        );

        // Verify "ES HORECA" is in Column H (8th column)
        $esHorecaHeader = $sheet->getCell('H1')->getValue();
        $this->assertEquals(
            'ES HORECA',
            $esHorecaHeader,
            'Column H should contain "ES HORECA" header'
        );

        // Verify "ES HORECA" header cell has proper styling (bold font, green background)
        $esHorecaStyle = $sheet->getStyle('H1');

        $this->assertTrue(
            $esHorecaStyle->getFont()->getBold(),
            'ES HORECA header (H1) should have bold font'
        );

        $this->assertEquals(
            'E2EFDA',
            $esHorecaStyle->getFill()->getStartColor()->getRGB(),
            'ES HORECA header (H1) should have green background (#E2EFDA)'
        );

        $this->assertEquals(
            \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            $esHorecaStyle->getFill()->getFillType(),
            'ES HORECA header (H1) should have solid fill type'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Helper: Generate plated dish ingredients template file
     *
     * @return string Path to generated file
     */
    protected function generateTemplate(): string
    {
        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate filename
        $fileName = 'test-exports/plated-dish-template-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        // Generate template
        Excel::store(
            new PlatedDishIngredientsTemplateExport(),
            $fileName,
            'local',
            \Maatwebsite\Excel\Excel::XLSX
        );

        $this->assertFileExists($fullPath, "Template file should be created at {$fullPath}");

        return $fullPath;
    }

    /**
     * Helper: Load Excel file
     *
     * @param string $filePath
     * @return Spreadsheet
     */
    protected function loadExcelFile(string $filePath): Spreadsheet
    {
        $this->assertFileExists($filePath, "Excel file should exist at {$filePath}");

        return IOFactory::load($filePath);
    }

    /**
     * Helper: Clean up test file
     *
     * @param string $filePath
     * @return void
     */
    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}