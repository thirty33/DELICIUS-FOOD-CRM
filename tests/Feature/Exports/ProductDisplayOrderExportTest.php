<?php

namespace Tests\Feature\Exports;

use App\Exports\ProductsDataExport;
use App\Exports\ProductsTemplateExport;
use App\Imports\Concerns\ProductColumnDefinition;
use App\Models\Category;
use App\Models\ExportProcess;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * TDD Red Phase - Product display_order Export/Template Tests
 *
 * These tests validate that the display_order field is included
 * in product exports and templates. They will FAIL until:
 * 1. 'orden' column is added to ProductColumnDefinition::COLUMNS
 * 2. 'display_order' field is added to products table
 * 3. Export map() includes display_order
 */
class ProductDisplayOrderExportTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'TEST CATEGORY',
            'code' => 'TST',
            'active' => true,
        ]);
    }

    public function test_export_headers_include_display_order_as_last_column(): void
    {
        $product = Product::create([
            'code' => 'TEST-DO-001',
            'name' => 'Test Display Order',
            'description' => 'Test',
            'price' => 100000,
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        $filePath = $this->generateProductExport(collect([$product->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Orden', $headers, 'Export headers should include "Orden" column');

        // "Orden" must be the LAST column, after "Codigo de Facturacion"
        $lastHeader = end($headers);
        $this->assertEquals('Orden', $lastHeader, '"Orden" should be the last column in export headers');

        $ordenIndex = array_search('Orden', $headers);
        $billingIndex = array_search('Codigo de Facturacion', $headers);
        $this->assertGreaterThan($billingIndex, $ordenIndex, '"Orden" should come after "Codigo de Facturacion"');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_product_display_order_correctly(): void
    {
        $product = Product::create([
            'code' => 'TEST-DO-002',
            'name' => 'Test Display Order Value',
            'description' => 'Test',
            'price' => 100000,
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
            'display_order' => 5,
        ]);

        $filePath = $this->generateProductExport(collect([$product->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $cell = ProductColumnDefinition::cell('orden', 2);
        $this->assertEquals(5, $sheet->getCell($cell)->getValue(), 'Display order should be exported as 5');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_default_display_order(): void
    {
        $product = Product::create([
            'code' => 'TEST-DO-003',
            'name' => 'Test Default Display Order',
            'description' => 'Test',
            'price' => 100000,
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        $filePath = $this->generateProductExport(collect([$product->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $cell = ProductColumnDefinition::cell('orden', 2);
        $this->assertEquals(9999, $sheet->getCell($cell)->getValue(), 'Default display order should be 9999');

        $this->cleanupTestFile($filePath);
    }

    public function test_template_includes_display_order_as_last_column(): void
    {
        $filePath = $this->generateProductTemplate();
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Orden', $headers, 'Template headers should include "Orden" column');

        // "Orden" must be the LAST column in template too
        $lastHeader = end($headers);
        $this->assertEquals('Orden', $lastHeader, '"Orden" should be the last column in template headers');

        $this->cleanupTestFile($filePath);
    }

    // ---------------------------------------------------------------
    // Helpers (same pattern as ProductsDataExportTest)
    // ---------------------------------------------------------------

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

    protected function generateProductExport(Collection $productIds): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (! is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_PRODUCTS,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $fileName = 'test-exports/products-do-export-'.now()->format('Y-m-d-His').'.xlsx';
        $fullPath = storage_path('app/'.$fileName);

        $export = new ProductsDataExport($productIds, $exportProcess->id);
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath);

        return $fullPath;
    }

    protected function generateProductTemplate(): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (! is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $fileName = 'test-exports/products-do-template-'.now()->format('Y-m-d-His').'.xlsx';
        $fullPath = storage_path('app/'.$fileName);

        $export = new ProductsTemplateExport;
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
