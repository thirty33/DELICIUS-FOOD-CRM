<?php

namespace Tests\Feature\Exports;

use App\Exports\NutritionalInformationTemplateExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * NutritionalInformationTemplateExport Test
 *
 * Validates that the template Excel file:
 * 1. Is generated successfully
 * 2. Has exactly 28 headers matching import expectations
 * 3. Has headers in the correct order
 * 4. Has only headers (no data rows)
 * 5. Has proper styling (bold, green background)
 *
 * This template is used by end-users to import nutritional information data.
 */
class NutritionalInformationTemplateExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that template file is generated successfully
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
     * Test that template has exactly 28 headers in correct order
     */
    public function test_template_has_28_headers_in_correct_order(): void
    {
        // Expected headers - MUST match NutritionalInformationImport expectations (28 columns)
        $expectedHeaders = [
            'CÓDIGO DE PRODUCTO',      // 1
            'NOMBRE DE PRODUCTO',      // 2
            'CODIGO DE BARRAS',        // 3
            'INGREDIENTE',             // 4
            'ALERGENOS',               // 5
            'UNIDAD DE MEDIDA',        // 6
            'PESO NETO',               // 7
            'PESO BRUTO',              // 8
            'CALORIAS',                // 9
            'PROTEINA',                // 10
            'GRASA',                   // 11
            'GRASA SATURADA',          // 12
            'GRASA MONOINSATURADA',    // 13
            'GRASA POLIINSATURADA',    // 14
            'GRASA TRANS',             // 15
            'COLESTEROL',              // 16
            'CARBOHIDRATO',            // 17
            'FIBRA',                   // 18
            'AZUCAR',                  // 19
            'SODIO',                   // 20
            'ALTO SODIO',              // 21
            'ALTO CALORIAS',           // 22
            'ALTO EN GRASAS',          // 23
            'ALTO EN AZUCARES',        // 24
            'VIDA UTIL',               // 25
            'GENERAR ETIQUETA',        // 26
            'MOSTRAR TEXTO SOYA',      // 27 - Warning text field
            'MOSTRAR TEXTO POLLO',     // 28 - Warning text field
        ];

        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Read headers from row 1 using numeric iteration (to support columns beyond Z)
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert we have exactly 28 headers
        $this->assertCount(28, $actualHeaders, 'Template should have exactly 28 headers (26 original + 2 warning text fields)');

        // Assert headers match exactly in correct order
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Template headers MUST match import expectations EXACTLY in the same order'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that warning text columns are positioned correctly (after VIDA UTIL and GENERAR ETIQUETA)
     */
    public function test_warning_text_columns_are_positioned_at_end(): void
    {
        // Generate template file
        $filePath = $this->generateTemplate();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Read headers from row 1 using numeric iteration
        $headers = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $headers[] = $headerValue;
            }
        }

        // Find column positions
        $vidaUtilIndex = array_search('VIDA UTIL', $headers);
        $generateLabelIndex = array_search('GENERAR ETIQUETA', $headers);
        $showSoyTextIndex = array_search('MOSTRAR TEXTO SOYA', $headers);
        $showChickenTextIndex = array_search('MOSTRAR TEXTO POLLO', $headers);

        // Assert specific positions
        $this->assertEquals(24, $vidaUtilIndex, 'VIDA UTIL should be at index 24 (column 25)');
        $this->assertEquals(25, $generateLabelIndex, 'GENERAR ETIQUETA should be at index 25 (column 26)');
        $this->assertEquals(26, $showSoyTextIndex, 'MOSTRAR TEXTO SOYA should be at index 26 (column 27)');
        $this->assertEquals(27, $showChickenTextIndex, 'MOSTRAR TEXTO POLLO should be at index 27 (column 28)');

        // Assert warning text columns come AFTER VIDA UTIL and GENERAR ETIQUETA
        $this->assertGreaterThan(
            $vidaUtilIndex,
            $showSoyTextIndex,
            'MOSTRAR TEXTO SOYA should come after VIDA UTIL'
        );

        $this->assertGreaterThan(
            $generateLabelIndex,
            $showSoyTextIndex,
            'MOSTRAR TEXTO SOYA should come after GENERAR ETIQUETA'
        );

        $this->assertGreaterThan(
            $showSoyTextIndex,
            $showChickenTextIndex,
            'MOSTRAR TEXTO POLLO should come after MOSTRAR TEXTO SOYA'
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
        $repository = app(\App\Contracts\NutritionalInformationRepositoryInterface::class);
        $importClass = new \App\Imports\NutritionalInformationImport($repository, 1);
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
            'Template headers MUST match NutritionalInformationImport::getExpectedHeaders() EXACTLY'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Helper: Generate nutritional information template file
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
        $fileName = 'test-exports/nutritional-info-template-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        // Generate template
        Excel::store(
            new NutritionalInformationTemplateExport(),
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