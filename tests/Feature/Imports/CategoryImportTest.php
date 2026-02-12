<?php

namespace Tests\Feature\Imports;

use App\Imports\CategoryImport;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\ImportProcess;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * CategoryImport Test - Validates category import from Excel files
 *
 * Tests the complete import flow for categories including:
 * - Basic category data (name, description, active)
 * - Subcategory relationships (comma-separated in Excel)
 * - Category group/keyword relationships (comma-separated in Excel)
 * - Update existing categories on re-import
 * - ExportProcess status transitions
 */
class CategoryImportTest extends TestCase
{
    use RefreshDatabase;
    use ConfiguresImportTests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureImportTest();
    }

    // ---------------------------------------------------------------
    // Import Tests
    // ---------------------------------------------------------------

    public function test_imports_categories_from_excel_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $initialCount = Category::count();

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_CATEGORIES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $testFile = base_path('tests/Fixtures/test_category_import.xlsx');
        $this->assertFileExists($testFile);

        Excel::import(
            new CategoryImport($importProcess->id),
            $testFile
        );

        $importProcess->refresh();
        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should finish as processed'
        );
        $this->assertNull($importProcess->error_log, 'No errors expected');

        $this->assertEquals($initialCount + 5, Category::count(), 'Should have imported 5 new categories');
    }

    public function test_imports_category_data_correctly(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_CATEGORIES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new CategoryImport($importProcess->id),
            base_path('tests/Fixtures/test_category_import.xlsx')
        );

        // Category 1: active, with subcategories and keywords
        $cat1 = Category::where('name', 'MINI ENSALADAS')->first();
        $this->assertNotNull($cat1);
        $this->assertEquals('Ensaladas pequeñas para acompañamiento', $cat1->description);
        $this->assertTrue((bool) $cat1->is_active);

        // Category 4: inactive, no subcategories, no keywords
        $cat4 = Category::where('name', 'BEBESTIBLES')->first();
        $this->assertNotNull($cat4);
        $this->assertFalse((bool) $cat4->is_active);
    }

    public function test_imports_subcategories_correctly(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_CATEGORIES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new CategoryImport($importProcess->id),
            base_path('tests/Fixtures/test_category_import.xlsx')
        );

        // MINI ENSALADAS has subcategories: ENTRADA, FRIA
        $cat1 = Category::where('name', 'MINI ENSALADAS')->first();
        $subcatNames = $cat1->subcategories->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['ENTRADA', 'FRIA'], $subcatNames);

        // PLATOS DE FONDO has subcategories: PLATO DE FONDO, CALIENTE
        $cat2 = Category::where('name', 'PLATOS DE FONDO')->first();
        $subcatNames2 = $cat2->subcategories->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['CALIENTE', 'PLATO DE FONDO'], $subcatNames2);

        // ACOMPAÑAMIENTOS has subcategory: PAN
        $cat5 = Category::where('name', 'ACOMPAÑAMIENTOS')->first();
        $this->assertEquals(['PAN'], $cat5->subcategories->pluck('name')->toArray());

        // POSTRES has no subcategories
        $cat3 = Category::where('name', 'POSTRES')->first();
        $this->assertCount(0, $cat3->subcategories);

        // Subcategory models should be created
        $this->assertEquals(5, Subcategory::count(), 'Should have 5 unique subcategories');
    }

    public function test_imports_category_groups_correctly(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_CATEGORIES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new CategoryImport($importProcess->id),
            base_path('tests/Fixtures/test_category_import.xlsx')
        );

        // MINI ENSALADAS has keywords: ensaladas, acompañamiento
        $cat1 = Category::where('name', 'MINI ENSALADAS')->first();
        $groupNames = $cat1->categoryGroups->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['acompañamiento', 'ensaladas'], $groupNames);

        // POSTRES has keyword: postres
        $cat3 = Category::where('name', 'POSTRES')->first();
        $this->assertEquals(['postres'], $cat3->categoryGroups->pluck('name')->toArray());

        // BEBESTIBLES has no keywords
        $cat4 = Category::where('name', 'BEBESTIBLES')->first();
        $this->assertCount(0, $cat4->categoryGroups);

        // CategoryGroup models should be created
        $expectedGroups = ['ensaladas', 'acompañamiento', 'platos', 'almuerzo', 'postres', 'pan', 'extras'];
        $this->assertEquals(count($expectedGroups), CategoryGroup::count());
    }

    public function test_reimport_updates_existing_categories(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $initialCount = Category::count();

        // First import
        $ip1 = ImportProcess::create([
            'type' => ImportProcess::TYPE_CATEGORIES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new CategoryImport($ip1->id),
            base_path('tests/Fixtures/test_category_import.xlsx')
        );

        $this->assertEquals($initialCount + 5, Category::count());

        // Modify a category manually
        $cat = Category::where('name', 'POSTRES')->first();
        $cat->update(['description' => 'Old description']);

        // Re-import should update, not duplicate
        $ip2 = ImportProcess::create([
            'type' => ImportProcess::TYPE_CATEGORIES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new CategoryImport($ip2->id),
            base_path('tests/Fixtures/test_category_import.xlsx')
        );

        $this->assertEquals($initialCount + 5, Category::count(), 'Should still have same count (not duplicated)');

        $cat->refresh();
        $this->assertEquals('Postres y dulces', $cat->description, 'Description should be updated from Excel');
    }

    public function test_import_process_status_transitions(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_CATEGORIES,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $this->assertEquals(ImportProcess::STATUS_QUEUED, $importProcess->status);

        Excel::import(
            new CategoryImport($importProcess->id),
            base_path('tests/Fixtures/test_category_import.xlsx')
        );

        $importProcess->refresh();
        $this->assertEquals(ImportProcess::STATUS_PROCESSED, $importProcess->status);
    }
}