<?php

namespace Tests\Feature\Exports;

use App\Exports\ProductsDataExport;
use App\Exports\ProductsTemplateExport;
use App\Models\Category;
use App\Models\ExportProcess;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductionArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * ProductsDataExport Test - Validates Product Export with All Fields
 *
 * This test validates the complete export flow for products,
 * including headers order and data accuracy.
 *
 * Test validates:
 * 1. Headers match import headers order (compatibility)
 * 2. Product data is exported correctly (all fields)
 * 3. Prices are formatted correctly ($X,XXX.XX)
 * 4. Ingredients are exported as comma-separated list
 * 5. Production areas are exported as comma-separated list
 * 6. Boolean fields are exported as VERDADERO/FALSO
 */
class ProductsDataExportTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;
    protected Product $product;
    protected ProductionArea $area1;
    protected ProductionArea $area2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create category
        $this->category = Category::create([
            'name' => 'MINI ENSALADAS',
            'code' => 'ENS',
            'active' => true,
        ]);

        // Create production areas
        $this->area1 = ProductionArea::create(['name' => 'CUARTO FRIO ENSALADAS']);
        $this->area2 = ProductionArea::create(['name' => 'EMPLATADO']);

        // Create product with all fields
        $this->product = Product::create([
            'code' => 'TEST-EXPORT-001',
            'name' => 'TEST - Producto Exportación',
            'description' => 'Descripción del producto de prueba',
            'price' => 125050, // $1,250.50 in cents
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'original_filename' => 'test_export_001.jpg',
            'price_list' => 135075, // $1,350.75 in cents
            'stock' => 150,
            'weight' => 0.35,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        // Associate production areas
        $this->product->productionAreas()->attach([$this->area1->id, $this->area2->id]);

        // Create ingredients
        Ingredient::create([
            'product_id' => $this->product->id,
            'descriptive_text' => 'Lechuga'
        ]);
        Ingredient::create([
            'product_id' => $this->product->id,
            'descriptive_text' => 'Tomate'
        ]);
        Ingredient::create([
            'product_id' => $this->product->id,
            'descriptive_text' => 'Pepino'
        ]);
    }

    /**
     * Test that headers are in the correct order matching import structure
     */
    public function test_export_headers_match_import_order(): void
    {
        // Expected headers (same order as ProductsImport)
        $expectedHeaders = [
            'Código',
            'Nombre',
            'Descripción',
            'Precio',
            'Categoría',
            'Unidad de Medida',
            'Nombre Archivo Original',
            'Precio Lista',
            'Stock',
            'Peso',
            'Permitir Ventas sin Stock',
            'Activo',
            'Ingredientes',
            'Áreas de Producción',
        ];

        // Generate export file
        $filePath = $this->generateProductExport(collect([$this->product->id]));

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Read headers from row 1
        $actualHeaders = [];
        $highestColumn = $sheet->getHighestColumn();
        $columnIndex = 1;

        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $headerValue = $sheet->getCell($col . '1')->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert headers match expected order
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Export headers should match import headers order exactly'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that product data is exported correctly
     */
    public function test_exports_product_data_correctly(): void
    {
        // Generate export file
        $filePath = $this->generateProductExport(collect([$this->product->id]));

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Row 1 is headers, row 2 is data
        $dataRow = 2;

        // Verify each column
        $this->assertEquals('TEST-EXPORT-001', $sheet->getCell('A' . $dataRow)->getValue(), 'Código should match');
        $this->assertEquals('TEST - Producto Exportación', $sheet->getCell('B' . $dataRow)->getValue(), 'Nombre should match');
        $this->assertEquals('Descripción del producto de prueba', $sheet->getCell('C' . $dataRow)->getValue(), 'Descripción should match');

        // Verify price formatting: $1,250.50
        $priceValue = $sheet->getCell('D' . $dataRow)->getValue();
        $this->assertEquals('$1,250.50', $priceValue, 'Precio should be formatted as $1,250.50');

        $this->assertEquals('MINI ENSALADAS', $sheet->getCell('E' . $dataRow)->getValue(), 'Categoría should match');
        $this->assertEquals('UND', $sheet->getCell('F' . $dataRow)->getValue(), 'Unidad de Medida should match');
        $this->assertEquals('test_export_001.jpg', $sheet->getCell('G' . $dataRow)->getValue(), 'Nombre Archivo Original should match');

        // Verify price list formatting: $1,350.75
        $priceListValue = $sheet->getCell('H' . $dataRow)->getValue();
        $this->assertEquals('$1,350.75', $priceListValue, 'Precio Lista should be formatted as $1,350.75');

        // Verify stock (with leading apostrophe for text format)
        $stockValue = $sheet->getCell('I' . $dataRow)->getValue();
        $this->assertEquals("'150", $stockValue, 'Stock should have leading apostrophe');

        // Verify weight (with leading apostrophe for text format)
        $weightValue = $sheet->getCell('J' . $dataRow)->getValue();
        $this->assertEquals("'0.35", $weightValue, 'Peso should have leading apostrophe');

        // Verify boolean flags
        $this->assertEquals('VERDADERO', $sheet->getCell('K' . $dataRow)->getValue(), 'Permitir Ventas sin Stock should be VERDADERO');
        $this->assertEquals('VERDADERO', $sheet->getCell('L' . $dataRow)->getValue(), 'Activo should be VERDADERO');

        // Verify ingredients (comma-separated)
        $ingredientsValue = $sheet->getCell('M' . $dataRow)->getValue();
        $this->assertStringContainsString('Lechuga', $ingredientsValue, 'Ingredients should contain Lechuga');
        $this->assertStringContainsString('Tomate', $ingredientsValue, 'Ingredients should contain Tomate');
        $this->assertStringContainsString('Pepino', $ingredientsValue, 'Ingredients should contain Pepino');

        // Verify production areas (comma-separated)
        $areasValue = $sheet->getCell('N' . $dataRow)->getValue();
        $this->assertStringContainsString('CUARTO FRIO ENSALADAS', $areasValue, 'Areas should contain CUARTO FRIO ENSALADAS');
        $this->assertStringContainsString('EMPLATADO', $areasValue, 'Areas should contain EMPLATADO');

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test export with multiple products
     */
    public function test_exports_multiple_products(): void
    {
        // Create second product
        $product2 = Product::create([
            'code' => 'TEST-EXPORT-002',
            'name' => 'TEST - Segundo Producto',
            'description' => 'Segunda descripción',
            'price' => 200000, // $2,000.00
            'category_id' => $this->category->id,
            'measure_unit' => 'KG',
            'stock' => 50,
            'weight' => 1.5,
            'allow_sales_without_stock' => false,
            'active' => false,
        ]);

        // Generate export with both products
        $filePath = $this->generateProductExport(collect([$this->product->id, $product2->id]));

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify we have 2 data rows (+ 1 header row)
        $lastRow = $sheet->getHighestRow();
        $this->assertEquals(3, $lastRow, 'Should have 1 header row + 2 data rows');

        // Verify first product (row 2)
        $this->assertEquals('TEST-EXPORT-001', $sheet->getCell('A2')->getValue());

        // Verify second product (row 3)
        $this->assertEquals('TEST-EXPORT-002', $sheet->getCell('A3')->getValue());
        $this->assertEquals('TEST - Segundo Producto', $sheet->getCell('B3')->getValue());
        $this->assertEquals('$2,000.00', $sheet->getCell('D3')->getValue());
        $this->assertEquals('FALSO', $sheet->getCell('K3')->getValue(), 'Allow sales should be FALSO');
        $this->assertEquals('FALSO', $sheet->getCell('L3')->getValue(), 'Active should be FALSO');

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test export with product without optional fields
     */
    public function test_exports_product_without_optional_fields(): void
    {
        // Create minimal product
        $minimalProduct = Product::create([
            'code' => 'TEST-MINIMAL-001',
            'name' => 'TEST - Producto Mínimo',
            'description' => 'Sin campos opcionales',
            'price' => 100000, // $1,000.00
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);
        // No ingredients, no production areas

        $filePath = $this->generateProductExport(collect([$minimalProduct->id]));

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify product code
        $this->assertEquals('TEST-MINIMAL-001', $sheet->getCell('A2')->getValue());

        // Verify ingredients column is empty
        $ingredientsValue = $sheet->getCell('M2')->getValue();
        $this->assertEmpty($ingredientsValue, 'Ingredients should be empty');

        // Verify production areas column is empty
        $areasValue = $sheet->getCell('N2')->getValue();
        $this->assertEmpty($areasValue, 'Production areas should be empty');

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that ProductsTemplateExport headers match ProductsDataExport headers
     */
    public function test_template_export_headers_match_data_export_headers(): void
    {
        // Expected headers (should match both template and data export)
        $expectedHeaders = [
            'Código',
            'Nombre',
            'Descripción',
            'Precio',
            'Categoría',
            'Unidad de Medida',
            'Nombre Archivo Original',
            'Precio Lista',
            'Stock',
            'Peso',
            'Permitir Ventas sin Stock',
            'Activo',
            'Ingredientes',
            'Áreas de Producción',
        ];

        // Generate template file
        $templatePath = $this->generateProductTemplate();

        // Load template file
        $spreadsheet = $this->loadExcelFile($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify template has only 1 row (headers only, no data)
        $lastRow = $sheet->getHighestRow();
        $this->assertEquals(1, $lastRow, 'Template should have only 1 row (headers)');

        // Read headers from template
        $actualHeaders = [];
        $highestColumn = $sheet->getHighestColumn();

        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $headerValue = $sheet->getCell($col . '1')->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert template headers match expected headers
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Template headers should match expected headers order exactly'
        );

        // Verify header styling (should have bold font and green background)
        $headerCell = $sheet->getCell('A1');
        $this->assertTrue(
            $headerCell->getStyle()->getFont()->getBold(),
            'Template headers should be bold'
        );

        $fillColor = $headerCell->getStyle()->getFill()->getStartColor()->getRGB();
        $this->assertEquals(
            'E2EFDA',
            $fillColor,
            'Template headers should have green background (E2EFDA)'
        );

        // Clean up
        $this->cleanupTestFile($templatePath);
    }

    /**
     * Generate product export file and return path
     */
    protected function generateProductExport(Collection $productIds): string
    {
        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Create export process
        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_PRODUCTS,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-'
        ]);

        // Generate filename
        $fileName = 'test-exports/products-export-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        // Create export instance
        $export = new ProductsDataExport($productIds, $exportProcess->id);

        // Generate file content (bypass queuing)
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        // Write to file
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath, "Excel file should be created at {$fullPath}");

        return $fullPath;
    }

    /**
     * Generate product template file and return path
     */
    protected function generateProductTemplate(): string
    {
        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate filename
        $fileName = 'test-exports/products-template-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        // Create template export instance
        $export = new ProductsTemplateExport();

        // Generate file content
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        // Write to file
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath, "Template file should be created at {$fullPath}");

        return $fullPath;
    }

    /**
     * Load Excel file and return Spreadsheet object
     */
    protected function loadExcelFile(string $filePath): Spreadsheet
    {
        $this->assertFileExists($filePath, "Excel file should exist at {$filePath}");

        return IOFactory::load($filePath);
    }

    /**
     * Clean up test file
     */
    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
