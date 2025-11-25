<?php

namespace Tests\Feature\Exports;

use App\Exports\NutritionalInformationDataExport;
use App\Models\Category;
use App\Models\ExportProcess;
use App\Models\NutritionalInformation;
use App\Models\NutritionalValue;
use App\Models\Product;
use App\Enums\NutritionalValueType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * NutritionalInformationDataExport Test - Validates Export Compatibility with Import
 *
 * This test validates that the export format matches EXACTLY what the
 * NutritionalInformationImport class expects, ensuring round-trip compatibility.
 *
 * Test validates:
 * 1. File is generated successfully
 * 2. Headers match EXACTLY what NutritionalInformationImport expects (26 headers)
 * 3. Excel data matches database data exactly
 * 4. All nutritional values are exported correctly
 */
class NutritionalInformationDataExportTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;
    protected Product $product1;
    protected Product $product2;
    protected NutritionalInformation $nutritionalInfo1;
    protected NutritionalInformation $nutritionalInfo2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create category
        $this->category = Category::create([
            'name' => 'TEST CATEGORY',
            'code' => 'TST',
            'active' => true,
        ]);

        // Create test products
        $this->product1 = Product::create([
            'code' => 'TEST-EXPORT-001',
            'name' => 'Test Product 1',
            'description' => 'Test product for export',
            'price' => 100000,
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        $this->product2 = Product::create([
            'code' => 'TEST-EXPORT-002',
            'name' => 'Test Product 2',
            'description' => 'Second test product',
            'price' => 150000,
            'category_id' => $this->category->id,
            'measure_unit' => 'GR',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        // Create nutritional information for product 1
        $this->nutritionalInfo1 = NutritionalInformation::create([
            'product_id' => $this->product1->id,
            'barcode' => '7501234567890',
            'ingredients' => 'Lechuga, Tomate, Pepino',
            'allergens' => 'Ninguno',
            'measure_unit' => 'GR',
            'net_weight' => 250.5,
            'gross_weight' => 300.75,
            'shelf_life_days' => 5,
            'generate_label' => true,
        ]);

        // Create nutritional values for product 1
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::CALORIES, 150.5);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::PROTEIN, 8.2);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::FAT_TOTAL, 10.5);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::FAT_SATURATED, 2.3);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::FAT_MONOUNSATURATED, 3.1);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::FAT_POLYUNSATURATED, 2.4);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::FAT_TRANS, 0.1);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::CHOLESTEROL, 15.0);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::CARBOHYDRATE, 25.8);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::FIBER, 4.5);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::SUGAR, 8.2);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::SODIUM, 120.5);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::HIGH_SODIUM, 1);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::HIGH_CALORIES, 0);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::HIGH_FAT, 1);
        $this->createNutritionalValue($this->nutritionalInfo1->id, NutritionalValueType::HIGH_SUGAR, 0);

        // Create nutritional information for product 2
        $this->nutritionalInfo2 = NutritionalInformation::create([
            'product_id' => $this->product2->id,
            'barcode' => '7509876543210',
            'ingredients' => 'Pollo, Arroz, Vegetales',
            'allergens' => 'Ninguno',
            'measure_unit' => 'GR',
            'net_weight' => 350.0,
            'gross_weight' => 400.0,
            'shelf_life_days' => 3,
            'generate_label' => false,
        ]);

        // Create nutritional values for product 2
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::CALORIES, 280.0);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::PROTEIN, 25.5);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::FAT_TOTAL, 8.0);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::FAT_SATURATED, 1.5);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::FAT_MONOUNSATURATED, 2.0);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::FAT_POLYUNSATURATED, 1.8);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::FAT_TRANS, 0.0);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::CHOLESTEROL, 55.0);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::CARBOHYDRATE, 35.2);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::FIBER, 3.0);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::SUGAR, 2.5);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::SODIUM, 200.0);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::HIGH_SODIUM, 0);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::HIGH_CALORIES, 1);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::HIGH_FAT, 0);
        $this->createNutritionalValue($this->nutritionalInfo2->id, NutritionalValueType::HIGH_SUGAR, 0);
    }

    /**
     * Test that export file is generated successfully
     */
    public function test_export_file_is_generated_successfully(): void
    {
        // Generate export file
        $filePath = $this->generateNutritionalExport(
            collect([$this->nutritionalInfo1->id, $this->nutritionalInfo2->id])
        );

        // Verify file exists
        $this->assertFileExists($filePath, 'Export file should be created');

        // Verify file is not empty
        $this->assertGreaterThan(0, filesize($filePath), 'Export file should not be empty');

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that headers match EXACTLY what NutritionalInformationImport expects
     */
    public function test_export_headers_match_import_expectations(): void
    {
        // Expected headers - MUST match NutritionalInformationImport headingRow() expectations
        $expectedHeaders = [
            'CÓDIGO DE PRODUCTO',
            'NOMBRE DE PRODUCTO',
            'CODIGO DE BARRAS',
            'INGREDIENTE',
            'ALERGENOS',
            'UNIDAD DE MEDIDA',
            'PESO NETO',
            'PESO BRUTO',
            'CALORIAS',
            'PROTEINA',
            'GRASA',
            'GRASA SATURADA',
            'GRASA MONOINSATURADA',
            'GRASA POLIINSATURADA',
            'GRASA TRANS',
            'COLESTEROL',
            'CARBOHIDRATO',
            'FIBRA',
            'AZUCAR',
            'SODIO',
            'ALTO SODIO',
            'ALTO CALORIAS',
            'ALTO EN GRASAS',
            'ALTO EN AZUCARES',
            'VIDA UTIL',
            'GENERAR ETIQUETA',
        ];

        // Generate export file
        $filePath = $this->generateNutritionalExport(
            collect([$this->nutritionalInfo1->id])
        );

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Read headers from row 1
        $actualHeaders = [];
        $highestColumn = $sheet->getHighestColumn();

        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $headerValue = $sheet->getCell($col . '1')->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert headers match EXACTLY
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Export headers MUST match import expectations EXACTLY'
        );

        // Verify we have exactly 26 headers
        $this->assertCount(26, $actualHeaders, 'Should have exactly 26 headers');

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that Excel data matches database data exactly
     */
    public function test_excel_data_matches_database_exactly(): void
    {
        // Generate export file
        $filePath = $this->generateNutritionalExport(
            collect([$this->nutritionalInfo1->id, $this->nutritionalInfo2->id])
        );

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify row count (1 header + 2 data rows)
        $lastRow = $sheet->getHighestRow();
        $this->assertEquals(3, $lastRow, 'Should have 1 header + 2 data rows');

        // ===== VERIFY PRODUCT 1 (Row 2) =====
        $this->assertEquals('TEST-EXPORT-001', $sheet->getCell('A2')->getValue(), 'Product 1: código de producto');
        $this->assertEquals('Test Product 1', $sheet->getCell('B2')->getValue(), 'Product 1: nombre de producto');
        $this->assertEquals('7501234567890', $sheet->getCell('C2')->getValue(), 'Product 1: código de barras');
        $this->assertEquals('Lechuga, Tomate, Pepino', $sheet->getCell('D2')->getValue(), 'Product 1: ingrediente');
        $this->assertEquals('Ninguno', $sheet->getCell('E2')->getValue(), 'Product 1: alergenos');
        $this->assertEquals('GR', $sheet->getCell('F2')->getValue(), 'Product 1: unidad de medida');
        $this->assertEquals(250.5, $sheet->getCell('G2')->getValue(), 'Product 1: peso neto');
        $this->assertEquals(300.75, $sheet->getCell('H2')->getValue(), 'Product 1: peso bruto');

        // Nutritional values for product 1
        $this->assertEquals(150.5, $sheet->getCell('I2')->getValue(), 'Product 1: calorias');
        $this->assertEquals(8.2, $sheet->getCell('J2')->getValue(), 'Product 1: proteina');
        $this->assertEquals(10.5, $sheet->getCell('K2')->getValue(), 'Product 1: grasa');
        $this->assertEquals(2.3, $sheet->getCell('L2')->getValue(), 'Product 1: grasa saturada');
        $this->assertEquals(3.1, $sheet->getCell('M2')->getValue(), 'Product 1: grasa monoinsaturada');
        $this->assertEquals(2.4, $sheet->getCell('N2')->getValue(), 'Product 1: grasa poliinsaturada');
        $this->assertEquals(0.1, $sheet->getCell('O2')->getValue(), 'Product 1: grasa trans');
        $this->assertEquals(15.0, $sheet->getCell('P2')->getValue(), 'Product 1: colesterol');
        $this->assertEquals(25.8, $sheet->getCell('Q2')->getValue(), 'Product 1: carbohidrato');
        $this->assertEquals(4.5, $sheet->getCell('R2')->getValue(), 'Product 1: fibra');
        $this->assertEquals(8.2, $sheet->getCell('S2')->getValue(), 'Product 1: azucar');
        $this->assertEquals(120.5, $sheet->getCell('T2')->getValue(), 'Product 1: sodio');

        // High indicators for product 1 (exported as 1/0)
        $this->assertEquals(1, $sheet->getCell('U2')->getValue(), 'Product 1: alto sodio');
        $this->assertEquals(0, $sheet->getCell('V2')->getValue(), 'Product 1: alto calorias');
        $this->assertEquals(1, $sheet->getCell('W2')->getValue(), 'Product 1: alto en grasas');
        $this->assertEquals(0, $sheet->getCell('X2')->getValue(), 'Product 1: alto en azucares');

        $this->assertEquals(5, $sheet->getCell('Y2')->getValue(), 'Product 1: vida util');
        $this->assertEquals(1, $sheet->getCell('Z2')->getValue(), 'Product 1: generar etiqueta');

        // ===== VERIFY PRODUCT 2 (Row 3) =====
        $this->assertEquals('TEST-EXPORT-002', $sheet->getCell('A3')->getValue(), 'Product 2: código de producto');
        $this->assertEquals('Test Product 2', $sheet->getCell('B3')->getValue(), 'Product 2: nombre de producto');
        $this->assertEquals('7509876543210', $sheet->getCell('C3')->getValue(), 'Product 2: código de barras');
        $this->assertEquals('Pollo, Arroz, Vegetales', $sheet->getCell('D3')->getValue(), 'Product 2: ingrediente');
        $this->assertEquals('Ninguno', $sheet->getCell('E3')->getValue(), 'Product 2: alergenos');
        $this->assertEquals('GR', $sheet->getCell('F3')->getValue(), 'Product 2: unidad de medida');
        $this->assertEquals(350.0, $sheet->getCell('G3')->getValue(), 'Product 2: peso neto');
        $this->assertEquals(400.0, $sheet->getCell('H3')->getValue(), 'Product 2: peso bruto');

        // Nutritional values for product 2
        $this->assertEquals(280.0, $sheet->getCell('I3')->getValue(), 'Product 2: calorias');
        $this->assertEquals(25.5, $sheet->getCell('J3')->getValue(), 'Product 2: proteina');
        $this->assertEquals(8.0, $sheet->getCell('K3')->getValue(), 'Product 2: grasa');
        $this->assertEquals(1.5, $sheet->getCell('L3')->getValue(), 'Product 2: grasa saturada');
        $this->assertEquals(2.0, $sheet->getCell('M3')->getValue(), 'Product 2: grasa monoinsaturada');
        $this->assertEquals(1.8, $sheet->getCell('N3')->getValue(), 'Product 2: grasa poliinsaturada');
        $this->assertEquals(0.0, $sheet->getCell('O3')->getValue(), 'Product 2: grasa trans');
        $this->assertEquals(55.0, $sheet->getCell('P3')->getValue(), 'Product 2: colesterol');
        $this->assertEquals(35.2, $sheet->getCell('Q3')->getValue(), 'Product 2: carbohidrato');
        $this->assertEquals(3.0, $sheet->getCell('R3')->getValue(), 'Product 2: fibra');
        $this->assertEquals(2.5, $sheet->getCell('S3')->getValue(), 'Product 2: azucar');
        $this->assertEquals(200.0, $sheet->getCell('T3')->getValue(), 'Product 2: sodio');

        // High indicators for product 2
        $this->assertEquals(0, $sheet->getCell('U3')->getValue(), 'Product 2: alto sodio');
        $this->assertEquals(1, $sheet->getCell('V3')->getValue(), 'Product 2: alto calorias');
        $this->assertEquals(0, $sheet->getCell('W3')->getValue(), 'Product 2: alto en grasas');
        $this->assertEquals(0, $sheet->getCell('X3')->getValue(), 'Product 2: alto en azucares');

        $this->assertEquals(3, $sheet->getCell('Y3')->getValue(), 'Product 2: vida util');
        $this->assertEquals(0, $sheet->getCell('Z3')->getValue(), 'Product 2: generar etiqueta');

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Helper: Create nutritional value
     */
    protected function createNutritionalValue(int $nutritionalInfoId, NutritionalValueType $type, float $value): void
    {
        NutritionalValue::create([
            'nutritional_information_id' => $nutritionalInfoId,
            'type' => $type,
            'value' => $value,
        ]);
    }

    /**
     * Helper: Generate nutritional information export file
     */
    protected function generateNutritionalExport(Collection $nutritionalInfoIds): string
    {
        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate filename
        $fileName = 'test-exports/nutritional-info-export-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        // Use ExportService to generate raw content
        $exportService = app(\App\Services\ExportService::class);
        $result = $exportService->exportRaw(
            \App\Exports\NutritionalInformationDataExport::class,
            $nutritionalInfoIds,
            ExportProcess::TYPE_NUTRITIONAL_INFORMATION
        );

        // Write to file
        file_put_contents($fullPath, $result['content']);

        $this->assertFileExists($fullPath, "Excel file should be created at {$fullPath}");

        return $fullPath;
    }

    /**
     * Helper: Load Excel file
     */
    protected function loadExcelFile(string $filePath): Spreadsheet
    {
        $this->assertFileExists($filePath, "Excel file should exist at {$filePath}");

        return IOFactory::load($filePath);
    }

    /**
     * Helper: Clean up test file
     */
    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}