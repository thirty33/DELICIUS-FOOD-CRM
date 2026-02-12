<?php

namespace Tests\Feature\Exports;

use App\Exports\ProductsDataExport;
use App\Exports\ProductsTemplateExport;
use App\Imports\Concerns\ProductColumnDefinition;
use App\Models\Category;
use App\Models\ExportProcess;
use App\Models\Ingredient;
use App\Models\MasterCategory;
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
 * Column positions are resolved via ProductColumnDefinition::cell() so that
 * adding, removing, or reordering columns only requires updating the
 * shared definition — not every assertion in this file.
 *
 * Test validates:
 * 1. Headers match ProductColumnDefinition order (compatibility)
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
     * Test that headers are in the correct order matching ProductColumnDefinition
     */
    public function test_export_headers_match_column_definition(): void
    {
        $filePath = $this->generateProductExport(collect([$this->product->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(
            ProductColumnDefinition::headers(),
            $this->readHeaderRow($sheet),
            'Export headers should match ProductColumnDefinition'
        );

        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that product data is exported correctly
     */
    public function test_exports_product_data_correctly(): void
    {
        $filePath = $this->generateProductExport(collect([$this->product->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $r = 2; // Row 1 = headers, Row 2 = first data row

        $this->assertCellEquals($sheet, 'codigo', $r, 'TEST-EXPORT-001');
        $this->assertCellEquals($sheet, 'nombre', $r, 'TEST - Producto Exportación');
        $this->assertCellEquals($sheet, 'descripcion', $r, 'Descripción del producto de prueba');
        $this->assertCellEquals($sheet, 'precio', $r, '$1,250.50');
        $this->assertCellEquals($sheet, 'categoria', $r, 'MINI ENSALADAS');
        $this->assertCellEquals($sheet, 'unidad_de_medida', $r, 'UND');
        $this->assertCellEquals($sheet, 'nombre_archivo_original', $r, 'test_export_001.jpg');
        $this->assertCellEquals($sheet, 'precio_lista', $r, '$1,350.75');
        $this->assertCellEquals($sheet, 'stock', $r, "'150");
        $this->assertCellEquals($sheet, 'peso', $r, "'0.35");
        $this->assertCellEquals($sheet, 'permitir_ventas_sin_stock', $r, 'VERDADERO');
        $this->assertCellEquals($sheet, 'activo', $r, 'VERDADERO');

        // Verify ingredients (comma-separated)
        $ingredientsValue = $sheet->getCell(ProductColumnDefinition::cell('ingredientes', $r))->getValue();
        $this->assertStringContainsString('Lechuga', $ingredientsValue);
        $this->assertStringContainsString('Tomate', $ingredientsValue);
        $this->assertStringContainsString('Pepino', $ingredientsValue);

        // Verify production areas (comma-separated)
        $areasValue = $sheet->getCell(ProductColumnDefinition::cell('areas_de_produccion', $r))->getValue();
        $this->assertStringContainsString('CUARTO FRIO ENSALADAS', $areasValue);
        $this->assertStringContainsString('EMPLATADO', $areasValue);

        $this->cleanupTestFile($filePath);
    }

    /**
     * Test export with multiple products
     */
    public function test_exports_multiple_products(): void
    {
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

        $filePath = $this->generateProductExport(collect([$this->product->id, $product2->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(3, $sheet->getHighestRow(), 'Should have 1 header row + 2 data rows');

        $this->assertCellEquals($sheet, 'codigo', 2, 'TEST-EXPORT-001');

        $this->assertCellEquals($sheet, 'codigo', 3, 'TEST-EXPORT-002');
        $this->assertCellEquals($sheet, 'nombre', 3, 'TEST - Segundo Producto');
        $this->assertCellEquals($sheet, 'precio', 3, '$2,000.00');
        $this->assertCellEquals($sheet, 'permitir_ventas_sin_stock', 3, 'FALSO');
        $this->assertCellEquals($sheet, 'activo', 3, 'FALSO');

        $this->cleanupTestFile($filePath);
    }

    /**
     * Test export with product without optional fields
     */
    public function test_exports_product_without_optional_fields(): void
    {
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

        $filePath = $this->generateProductExport(collect([$minimalProduct->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $r = 2;

        $this->assertCellEquals($sheet, 'codigo', $r, 'TEST-MINIMAL-001');
        $this->assertCellEmpty($sheet, 'ingredientes', $r, 'Ingredients should be empty');
        $this->assertCellEmpty($sheet, 'areas_de_produccion', $r, 'Production areas should be empty');

        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that ProductsTemplateExport headers match ProductColumnDefinition
     */
    public function test_template_headers_match_column_definition(): void
    {
        $templatePath = $this->generateProductTemplate();
        $sheet = $this->loadExcelFile($templatePath)->getActiveSheet();

        $this->assertEquals(1, $sheet->getHighestRow(), 'Template should have only 1 row (headers)');

        $this->assertEquals(
            ProductColumnDefinition::headers(),
            $this->readHeaderRow($sheet),
            'Template headers should match ProductColumnDefinition'
        );

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

        $this->cleanupTestFile($templatePath);
    }

    /**
     * Test that template headers and data export headers are identical
     */
    public function test_template_headers_match_data_export_headers(): void
    {
        $templatePath = $this->generateProductTemplate();
        $dataPath = $this->generateProductExport(collect([$this->product->id]));

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

    public function test_exports_billing_code_when_present(): void
    {
        $this->product->update(['billing_code' => 'FACT-EXPORT-001']);

        $filePath = $this->generateProductExport(collect([$this->product->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Codigo de Facturacion', $headers);

        $this->assertCellEquals($sheet, 'codigo_de_facturacion', 2, 'FACT-EXPORT-001');

        $this->cleanupTestFile($filePath);
    }

    public function test_template_includes_billing_code_column(): void
    {
        $filePath = $this->generateProductTemplate();
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Codigo de Facturacion', $headers);

        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that master categories are exported as comma-separated values
     * in the "Categoria Maestra" column, placed before "Categoría".
     */
    public function test_exports_master_categories_when_present(): void
    {
        // Associate master categories to the product's category
        $mc1 = MasterCategory::create(['name' => 'Almuerzos']);
        $mc2 = MasterCategory::create(['name' => 'Platos Fríos']);
        $this->category->masterCategories()->sync([$mc1->id, $mc2->id]);

        $filePath = $this->generateProductExport(collect([$this->product->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Categoria Maestra', $headers);

        // Verify "Categoria Maestra" comes before "Categoría"
        $mcIndex = array_search('Categoria Maestra', $headers);
        $catIndex = array_search('Categoría', $headers);
        $this->assertLessThan($catIndex, $mcIndex, '"Categoria Maestra" should come before "Categoría"');

        // Verify comma-separated values
        $mcValue = $sheet->getCell(ProductColumnDefinition::cell('categoria_maestra', 2))->getValue();
        $mcNames = array_map('trim', explode(',', $mcValue));
        sort($mcNames);
        $this->assertEquals(['Almuerzos', 'Platos Fríos'], $mcNames);

        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that master category column is empty when category has no master categories.
     */
    public function test_exports_empty_master_category_when_none_associated(): void
    {
        // No master categories associated
        $filePath = $this->generateProductExport(collect([$this->product->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Categoria Maestra', $headers);

        $this->assertCellEmpty($sheet, 'categoria_maestra', 2, 'Master category should be empty when none associated');

        $this->cleanupTestFile($filePath);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Assert a cell value by column key (resolves letter automatically).
     */
    private function assertCellEquals($sheet, string $columnKey, int $row, mixed $expected, ?string $message = null): void
    {
        $cell = ProductColumnDefinition::cell($columnKey, $row);
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
        $cell = ProductColumnDefinition::cell($columnKey, $row);
        $this->assertEmpty(
            $sheet->getCell($cell)->getValue(),
            $message ?? "Cell {$cell} ({$columnKey}) should be empty"
        );
    }

    protected function readHeaderRow($sheet): array
    {
        $headers = [];
        $highestColumn = $sheet->getHighestColumn();

        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $value = $sheet->getCell($col . '1')->getValue();
            if ($value) {
                $headers[] = $value;
            }
        }

        return $headers;
    }

    protected function generateProductExport(Collection $productIds): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_PRODUCTS,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-'
        ]);

        $fileName = 'test-exports/products-export-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        $export = new ProductsDataExport($productIds, $exportProcess->id);
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath);

        return $fullPath;
    }

    protected function generateProductTemplate(): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $fileName = 'test-exports/products-template-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        $export = new ProductsTemplateExport();
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

    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}