<?php

namespace Tests\Feature\Imports;

use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ImportProcess;
use App\Services\ImportService;
use App\Imports\CategoryMenuImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TDD RED PHASE - CategoryMenu Import: Product Display Order by Position
 *
 * These tests validate that when importing CategoryMenu with products in the
 * LEGACY format (comma-separated codes WITHOUT explicit :order), the display_order
 * is assigned based on the POSITION in the list.
 *
 * LEGACY FORMAT (what these tests validate):
 * - Input: "PROD001,PROD002,PROD003"
 * - Expected display_order: PROD001=1, PROD002=2, PROD003=3
 *
 * NEW FORMAT (already implemented, NOT tested here):
 * - Input: "PROD001:10,PROD002:20,PROD003:5"
 * - Expected display_order: PROD001=10, PROD002=20, PROD003=5
 *
 * CURRENT BEHAVIOR (will fail):
 * - Legacy format assigns display_order = 9999 to all products
 *
 * EXPECTED BEHAVIOR (after fix):
 * - Legacy format assigns display_order = 1, 2, 3... based on position
 */
class CategoryMenuProductPositionOrderTest extends TestCase
{
    use RefreshDatabase;

    private Role $role;
    private Permission $permission;
    private Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->role = Role::create([
            'name' => 'Convenio',
            'guard_name' => 'web',
        ]);

        $this->permission = Permission::create([
            'name' => 'Individual',
            'guard_name' => 'web',
        ]);

        $this->menu = Menu::create([
            'title' => 'POSITION ORDER TEST MENU',
            'description' => 'Menu for testing position-based display_order',
            'publication_date' => '2026-04-01',
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'max_order_date' => '2026-03-31 18:00:00',
            'active' => true,
        ]);
    }

    /**
     * Helper: Create category with products
     */
    private function createCategoryWithProducts(string $categoryCode, int $productCount): array
    {
        $category = Category::create([
            'name' => "CATEGORY {$categoryCode}",
            'code' => $categoryCode,
            'description' => "Test category {$categoryCode}",
            'active' => true,
        ]);

        $products = [];
        for ($i = 1; $i <= $productCount; $i++) {
            $products[] = Product::create([
                'name' => "Product {$categoryCode}-{$i}",
                'description' => "Product {$i} for category {$categoryCode}",
                'code' => "{$categoryCode}PROD" . str_pad($i, 3, '0', STR_PAD_LEFT),
                'category_id' => $category->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);
        }

        return ['category' => $category, 'products' => $products];
    }

    /**
     * Helper: Create Excel file for import
     */
    private function createImportExcelFile(array $rows): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $headers = [
            'Título del Menú',
            'Nombre de Categoría',
            'Mostrar Todos los Productos',
            'Orden de Visualización',
            'Categoría Obligatoria',
            'Activo',
            'Productos'
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
        }

        // Data rows
        foreach ($rows as $rowIndex => $rowData) {
            $excelRow = $rowIndex + 2;
            $sheet->setCellValueByColumnAndRow(1, $excelRow, $rowData['menu_title']);
            $sheet->setCellValueByColumnAndRow(2, $excelRow, $rowData['category_name']);
            $sheet->setCellValueByColumnAndRow(3, $excelRow, $rowData['show_all_products']);
            $sheet->setCellValueByColumnAndRow(4, $excelRow, $rowData['display_order']);
            $sheet->setCellValueByColumnAndRow(5, $excelRow, $rowData['mandatory_category']);
            $sheet->setCellValueByColumnAndRow(6, $excelRow, $rowData['is_active']);
            $sheet->setCellValueByColumnAndRow(7, $excelRow, $rowData['products']);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'category_menu_test_') . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Helper: Run category menu import
     */
    private function runImport(string $filePath): ImportProcess
    {
        $importService = app(ImportService::class);

        return $importService->import(
            CategoryMenuImport::class,
            $filePath,
            ImportProcess::TYPE_MENU_CATEGORIES
        );
    }

    /**
     * TDD RED PHASE TEST
     *
     * Test: When show_all_products = false and products are in LEGACY format (no :order),
     * the display_order should be assigned based on position in the comma-separated list.
     *
     * SCENARIO:
     * - Products column: "APROD001,APROD002,APROD003,APROD004"
     * - Expected display_order:
     *   - APROD001 = 1 (position 1)
     *   - APROD002 = 2 (position 2)
     *   - APROD003 = 3 (position 3)
     *   - APROD004 = 4 (position 4)
     *
     * CURRENT BEHAVIOR: All products get display_order = 9999
     * EXPECTED BEHAVIOR: Products get display_order = 1, 2, 3, 4 based on position
     */
    public function test_show_all_products_false_assigns_display_order_by_position(): void
    {
        // Create category with 4 products
        $data = $this->createCategoryWithProducts('A', 4);
        $category = $data['category'];
        $products = $data['products'];

        // Create Excel file with legacy format (no :order)
        $excelFile = $this->createImportExcelFile([
            [
                'menu_title' => $this->menu->title,
                'category_name' => $category->name,
                'show_all_products' => '0',
                'display_order' => 1,
                'mandatory_category' => '0',
                'is_active' => '1',
                'products' => 'APROD001,APROD002,APROD003,APROD004', // Legacy format
            ],
        ]);

        // Run import
        $importProcess = $this->runImport($excelFile);

        // Verify import was successful
        $importService = app(ImportService::class);
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should be successful. Status: ' . $importProcess->status .
            ' Errors: ' . json_encode($importProcess->error_log)
        );

        // Get the created CategoryMenu
        $categoryMenu = CategoryMenu::where('menu_id', $this->menu->id)
            ->where('category_id', $category->id)
            ->first();

        $this->assertNotNull($categoryMenu, 'CategoryMenu should be created');
        $this->assertFalse($categoryMenu->show_all_products, 'show_all_products should be false');
        $this->assertEquals(4, $categoryMenu->products()->count(), 'Should have 4 products attached');

        // TDD RED PHASE: Verify display_order is assigned by position
        // This will FAIL because current implementation assigns 9999 to all
        $pivotProduct1 = $categoryMenu->products()->where('product_id', $products[0]->id)->first();
        $this->assertEquals(
            1,
            $pivotProduct1->pivot->display_order,
            "APROD001 should have display_order = 1 (position 1). " .
            "TDD RED: Currently gets 9999 because legacy format doesn't assign position-based order."
        );

        $pivotProduct2 = $categoryMenu->products()->where('product_id', $products[1]->id)->first();
        $this->assertEquals(
            2,
            $pivotProduct2->pivot->display_order,
            "APROD002 should have display_order = 2 (position 2). " .
            "TDD RED: Currently gets 9999."
        );

        $pivotProduct3 = $categoryMenu->products()->where('product_id', $products[2]->id)->first();
        $this->assertEquals(
            3,
            $pivotProduct3->pivot->display_order,
            "APROD003 should have display_order = 3 (position 3). " .
            "TDD RED: Currently gets 9999."
        );

        $pivotProduct4 = $categoryMenu->products()->where('product_id', $products[3]->id)->first();
        $this->assertEquals(
            4,
            $pivotProduct4->pivot->display_order,
            "APROD004 should have display_order = 4 (position 4). " .
            "TDD RED: Currently gets 9999."
        );

        // Cleanup temp file
        @unlink($excelFile);
    }

    /**
     * TDD RED PHASE TEST
     *
     * Test: When show_all_products = true and products are specified in LEGACY format,
     * the specified products should get display_order by position, and
     * the rest of products should get display_order = 9999.
     *
     * SCENARIO:
     * - Category has 5 products: BPROD001, BPROD002, BPROD003, BPROD004, BPROD005
     * - Products column: "BPROD003,BPROD001" (only 2 specified, legacy format)
     * - Expected display_order:
     *   - BPROD003 = 1 (position 1 in the list)
     *   - BPROD001 = 2 (position 2 in the list)
     *   - BPROD002 = 9999 (not specified, default)
     *   - BPROD004 = 9999 (not specified, default)
     *   - BPROD005 = 9999 (not specified, default)
     *
     * CURRENT BEHAVIOR: All products get display_order = 9999
     * EXPECTED BEHAVIOR: Specified products get position-based order, others get 9999
     */
    public function test_show_all_products_true_assigns_display_order_by_position_for_specified_products(): void
    {
        // Create category with 5 products
        $data = $this->createCategoryWithProducts('B', 5);
        $category = $data['category'];
        $products = $data['products'];

        // Create Excel file with legacy format - only specify 2 products in reverse order
        $excelFile = $this->createImportExcelFile([
            [
                'menu_title' => $this->menu->title,
                'category_name' => $category->name,
                'show_all_products' => '1',
                'display_order' => 1,
                'mandatory_category' => '0',
                'is_active' => '1',
                'products' => 'BPROD003,BPROD001', // Legacy format, 2 products in specific order
            ],
        ]);

        // Run import
        $importProcess = $this->runImport($excelFile);

        // Verify import was successful
        $importService = app(ImportService::class);
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should be successful. Status: ' . $importProcess->status .
            ' Errors: ' . json_encode($importProcess->error_log)
        );

        // Get the created CategoryMenu
        $categoryMenu = CategoryMenu::where('menu_id', $this->menu->id)
            ->where('category_id', $category->id)
            ->first();

        $this->assertNotNull($categoryMenu, 'CategoryMenu should be created');
        $this->assertTrue($categoryMenu->show_all_products, 'show_all_products should be true');
        $this->assertEquals(5, $categoryMenu->products()->count(), 'Should have ALL 5 products attached');

        // TDD RED PHASE: Verify display_order for specified products (by position)
        // BPROD003 is first in list -> display_order = 1
        $pivotProduct3 = $categoryMenu->products()->where('product_id', $products[2]->id)->first();
        $this->assertEquals(
            1,
            $pivotProduct3->pivot->display_order,
            "BPROD003 should have display_order = 1 (position 1 in list). " .
            "TDD RED: Currently gets 9999."
        );

        // BPROD001 is second in list -> display_order = 2
        $pivotProduct1 = $categoryMenu->products()->where('product_id', $products[0]->id)->first();
        $this->assertEquals(
            2,
            $pivotProduct1->pivot->display_order,
            "BPROD001 should have display_order = 2 (position 2 in list). " .
            "TDD RED: Currently gets 9999."
        );

        // Products NOT in the list should have default display_order = 9999
        $pivotProduct2 = $categoryMenu->products()->where('product_id', $products[1]->id)->first();
        $this->assertEquals(
            9999,
            $pivotProduct2->pivot->display_order,
            "BPROD002 should have display_order = 9999 (not specified in list)."
        );

        $pivotProduct4 = $categoryMenu->products()->where('product_id', $products[3]->id)->first();
        $this->assertEquals(
            9999,
            $pivotProduct4->pivot->display_order,
            "BPROD004 should have display_order = 9999 (not specified in list)."
        );

        $pivotProduct5 = $categoryMenu->products()->where('product_id', $products[4]->id)->first();
        $this->assertEquals(
            9999,
            $pivotProduct5->pivot->display_order,
            "BPROD005 should have display_order = 9999 (not specified in list)."
        );

        // Cleanup temp file
        @unlink($excelFile);
    }
}