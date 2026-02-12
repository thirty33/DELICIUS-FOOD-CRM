<?php

namespace Tests\Feature\Exports;

use App\Exports\CategoryExport;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\ExportProcess;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * CategoryExport Test - Validates category export to Excel files
 *
 * Tests the complete export flow for categories including:
 * - Correct headers (Nombre, Descripción, Activo, Subcategorías, Palabras clave)
 * - Data mapping (name, description, active flag, subcategories, category groups)
 * - Header styling (bold, green background)
 * - ExportProcess status transitions
 * - Multiple categories with varied data
 */
class CategoryExportTest extends TestCase
{
    use RefreshDatabase;

    protected Category $cat1;
    protected Category $cat2;
    protected Category $cat3;

    protected function setUp(): void
    {
        parent::setUp();

        // Category 1: active, with subcategories and keywords
        $this->cat1 = Category::create([
            'name' => 'MINI ENSALADAS',
            'description' => 'Ensaladas pequeñas',
        ]);

        $sub1 = Subcategory::create(['name' => 'ENTRADA']);
        $sub2 = Subcategory::create(['name' => 'FRIA']);
        $this->cat1->subcategories()->sync([$sub1->id, $sub2->id]);

        $grp1 = CategoryGroup::create(['name' => 'ensaladas']);
        $grp2 = CategoryGroup::create(['name' => 'acompañamiento']);
        $this->cat1->categoryGroups()->sync([$grp1->id, $grp2->id]);

        // Category 2: inactive, no subcategories, no keywords
        $this->cat2 = Category::create([
            'name' => 'BEBESTIBLES',
            'description' => 'Bebidas y jugos',
            'is_active' => false,
        ]);

        // Category 3: active, one subcategory, one keyword
        $this->cat3 = Category::create([
            'name' => 'POSTRES',
            'description' => null,
        ]);

        $grp3 = CategoryGroup::create(['name' => 'postres']);
        $this->cat3->categoryGroups()->sync([$grp3->id]);
    }

    // ---------------------------------------------------------------
    // Header Tests
    // ---------------------------------------------------------------

    public function test_export_headers_are_correct(): void
    {
        $filePath = $this->generateCategoryExport(
            collect([$this->cat1, $this->cat2, $this->cat3])
        );
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $expectedHeaders = ['Nombre', 'Descripción', 'Activo', 'Subcategorías', 'Palabras clave'];
        $this->assertEquals($expectedHeaders, $this->readHeaderRow($sheet));

        $this->cleanupTestFile($filePath);
    }

    public function test_headers_are_styled(): void
    {
        $filePath = $this->generateCategoryExport(
            collect([$this->cat1])
        );
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

    public function test_exports_category_with_subcategories_and_keywords(): void
    {
        $filePath = $this->generateCategoryExport(collect([$this->cat1]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $r = 2;
        $this->assertEquals('MINI ENSALADAS', $sheet->getCell("A{$r}")->getValue());
        $this->assertEquals('Ensaladas pequeñas', $sheet->getCell("B{$r}")->getValue());
        $this->assertEquals('1', $sheet->getCell("C{$r}")->getValue());

        // Subcategories (comma-separated, order may vary)
        $subcats = explode(', ', $sheet->getCell("D{$r}")->getValue());
        sort($subcats);
        $this->assertEquals(['ENTRADA', 'FRIA'], $subcats);

        // Keywords (comma-separated, order may vary)
        $keywords = explode(', ', $sheet->getCell("E{$r}")->getValue());
        sort($keywords);
        $this->assertEquals(['acompañamiento', 'ensaladas'], $keywords);

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_inactive_category_without_relations(): void
    {
        $filePath = $this->generateCategoryExport(collect([$this->cat2]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $r = 2;
        $this->assertEquals('BEBESTIBLES', $sheet->getCell("A{$r}")->getValue());
        $this->assertEquals('Bebidas y jugos', $sheet->getCell("B{$r}")->getValue());
        $this->assertEquals('0', $sheet->getCell("C{$r}")->getValue());
        $this->assertEmpty($sheet->getCell("D{$r}")->getValue(), 'No subcategories');
        $this->assertEmpty($sheet->getCell("E{$r}")->getValue(), 'No keywords');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_multiple_categories(): void
    {
        $filePath = $this->generateCategoryExport(
            collect([$this->cat1, $this->cat2, $this->cat3])
        );
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(4, $sheet->getHighestRow(), 'Should have 1 header + 3 data rows');

        $names = [];
        for ($r = 2; $r <= 4; $r++) {
            $names[] = $sheet->getCell("A{$r}")->getValue();
        }

        $this->assertContains('MINI ENSALADAS', $names);
        $this->assertContains('BEBESTIBLES', $names);
        $this->assertContains('POSTRES', $names);

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_category_with_null_description(): void
    {
        $filePath = $this->generateCategoryExport(collect([$this->cat3]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $r = 2;
        $this->assertEquals('POSTRES', $sheet->getCell("A{$r}")->getValue());
        $this->assertNull($sheet->getCell("B{$r}")->getValue(), 'Description should be null');
        $this->assertEquals('1', $sheet->getCell("C{$r}")->getValue());

        $this->cleanupTestFile($filePath);
    }

    public function test_export_process_status_transitions(): void
    {
        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_CATEGORIES,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $this->assertEquals(ExportProcess::STATUS_QUEUED, $exportProcess->status);

        $export = new CategoryExport(collect([$this->cat1]), $exportProcess->id);
        Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        $exportProcess->refresh();
        $this->assertEquals(ExportProcess::STATUS_PROCESSED, $exportProcess->status);
    }

    // ---------------------------------------------------------------
    // Round-trip Test (Export → Import)
    // ---------------------------------------------------------------

    public function test_exported_file_can_be_reimported(): void
    {
        // Export
        $filePath = $this->generateCategoryExport(
            collect([$this->cat1, $this->cat2, $this->cat3])
        );

        // Clear all categories to simulate fresh import
        \Illuminate\Support\Facades\DB::table('category_category_group')->delete();
        \Illuminate\Support\Facades\DB::table('category_subcategory')->delete();
        Category::query()->delete();
        $this->assertEquals(0, Category::count());

        // Configure for import
        config(['queue.default' => 'sync']);
        config(['filesystems.default' => 's3']);
        config(['excel.temporary_files.remote_disk' => 's3']);
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Import the exported file
        $importProcess = \App\Models\ImportProcess::create([
            'type' => \App\Models\ImportProcess::TYPE_CATEGORIES,
            'status' => \App\Models\ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        \Maatwebsite\Excel\Facades\Excel::import(
            new \App\Imports\CategoryImport($importProcess->id),
            $filePath
        );

        $importProcess->refresh();
        $this->assertEquals(
            \App\Models\ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Re-import should succeed without errors'
        );

        $this->assertEquals(3, Category::count(), 'Should have re-imported 3 categories');

        // Verify round-trip data integrity
        $cat1 = Category::where('name', 'MINI ENSALADAS')->first();
        $this->assertNotNull($cat1);
        $this->assertEquals('Ensaladas pequeñas', $cat1->description);
        $this->assertTrue((bool) $cat1->is_active);

        $subcatNames = $cat1->subcategories->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['ENTRADA', 'FRIA'], $subcatNames);

        $cat2 = Category::where('name', 'BEBESTIBLES')->first();
        $this->assertFalse((bool) $cat2->is_active);

        $this->cleanupTestFile($filePath);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

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

    protected function generateCategoryExport(Collection $categories): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_CATEGORIES,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $fileName = 'test-exports/category-export-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        $export = new CategoryExport($categories, $exportProcess->id);
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