<?php

namespace Tests\Feature\Exports;

use App\Exports\MenuDataExport;
use App\Exports\CategoryMenuDataExport;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\ExportProcess;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Services\ExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Menu and CategoryMenu Export Test - Validates Export Compatibility with Import
 *
 * This test validates that the export format matches EXACTLY what the
 * MenusImport and CategoryMenuImport classes expect, ensuring round-trip compatibility.
 *
 * Test validates:
 * 1. File is generated successfully
 * 2. Headers match EXACTLY what Import expects
 * 3. Excel data matches database data exactly
 * 4. All fields are exported correctly
 */
class MenuAndCategoryMenuExportTest extends TestCase
{
    use RefreshDatabase;

    protected Role $role;
    protected Permission $permission;
    protected array $menus = [];
    protected array $categories = [];
    protected array $categoryMenus = [];
    protected array $products = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create required role and permission
        $this->role = Role::create([
            'name' => 'Convenio',
            'guard_name' => 'web',
        ]);

        $this->permission = Permission::create([
            'name' => 'Individual',
            'guard_name' => 'web',
        ]);

        // Create 3 menus with different configurations
        for ($m = 1; $m <= 3; $m++) {
            $menu = Menu::create([
                'title' => "EXPORT TEST MENU {$m}",
                'description' => "Menu description {$m}",
                'publication_date' => Carbon::now()->addDays($m)->format('Y-m-d'),
                'role_id' => $this->role->id,
                'permissions_id' => $this->permission->id,
                'max_order_date' => Carbon::now()->addDays($m - 1)->setTime(18, 0, 0),
                'active' => true,
            ]);
            $this->menus[$m] = $menu;
        }

        // Create 5 categories
        for ($c = 1; $c <= 5; $c++) {
            $category = Category::create([
                'name' => "EXPORT CATEGORY {$c}",
                'code' => "EXPCAT{$c}",
                'description' => "Export test category {$c}",
                'active' => true,
            ]);
            $this->categories[$c] = $category;
        }

        // Create some products for category 3 and 4
        $this->products[] = Product::create([
            'name' => 'Export Product 001',
            'description' => 'Product for export testing',
            'code' => 'EXPPROD001',
            'category_id' => $this->categories[3]->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $this->products[] = Product::create([
            'name' => 'Export Product 002',
            'description' => 'Product for export testing',
            'code' => 'EXPPROD002',
            'category_id' => $this->categories[3]->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $this->products[] = Product::create([
            'name' => 'Export Product 003',
            'description' => 'Product for export testing',
            'code' => 'EXPPROD003',
            'category_id' => $this->categories[4]->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create CategoryMenus for Menu 1 (5 category menus)
        $menu1 = $this->menus[1];

        // Category 1: Show all products
        $this->categoryMenus[] = CategoryMenu::create([
            'menu_id' => $menu1->id,
            'category_id' => $this->categories[1]->id,
            'show_all_products' => true,
            'display_order' => 1,
            'mandatory_category' => false,
            'is_active' => true,
        ]);

        // Category 2: Show all products
        $this->categoryMenus[] = CategoryMenu::create([
            'menu_id' => $menu1->id,
            'category_id' => $this->categories[2]->id,
            'show_all_products' => true,
            'display_order' => 2,
            'mandatory_category' => false,
            'is_active' => true,
        ]);

        // Category 3: Show specific products (EXPPROD001, EXPPROD002)
        $categoryMenu3 = CategoryMenu::create([
            'menu_id' => $menu1->id,
            'category_id' => $this->categories[3]->id,
            'show_all_products' => false,
            'display_order' => 3,
            'mandatory_category' => true,
            'is_active' => true,
        ]);
        $categoryMenu3->products()->attach([$this->products[0]->id, $this->products[1]->id]);
        $this->categoryMenus[] = $categoryMenu3;

        // Category 4: Show specific product (EXPPROD003)
        $categoryMenu4 = CategoryMenu::create([
            'menu_id' => $menu1->id,
            'category_id' => $this->categories[4]->id,
            'show_all_products' => false,
            'display_order' => 4,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenu4->products()->attach([$this->products[2]->id]);
        $this->categoryMenus[] = $categoryMenu4;

        // Category 5: Show all products, not active
        $this->categoryMenus[] = CategoryMenu::create([
            'menu_id' => $menu1->id,
            'category_id' => $this->categories[5]->id,
            'show_all_products' => true,
            'display_order' => 5,
            'mandatory_category' => false,
            'is_active' => false,
        ]);
    }

    /**
     * Helper: Generate menu export file
     */
    protected function generateMenuExport(Collection $menuIds): string
    {
        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate filename
        $fileName = 'test-exports/menu-export-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        // Use ExportService to generate raw content
        $exportService = app(ExportService::class);
        $result = $exportService->exportRaw(
            MenuDataExport::class,
            $menuIds,
            ExportProcess::TYPE_MENUS
        );

        // Write to file
        file_put_contents($fullPath, $result['content']);

        $this->assertFileExists($fullPath, "Excel file should be created at {$fullPath}");

        return $fullPath;
    }

    /**
     * Helper: Generate category menu export file
     */
    protected function generateCategoryMenuExport(Collection $categoryMenuIds): string
    {
        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate filename
        $fileName = 'test-exports/category-menu-export-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        // Use ExportService to generate raw content
        $exportService = app(ExportService::class);
        $result = $exportService->exportRaw(
            CategoryMenuDataExport::class,
            $categoryMenuIds,
            ExportProcess::TYPE_MENU_CATEGORIES
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

    /**
     * Test: Menu export file is generated successfully with correct headers and data
     */
    public function test_menu_export_generates_file_with_correct_headers_and_data(): void
    {
        // Collect menu IDs
        $menuIds = collect($this->menus)->pluck('id');

        // Generate export file
        $filePath = $this->generateMenuExport($menuIds);

        // ===== 1. VERIFY FILE EXISTS =====
        $this->assertFileExists($filePath, 'Export file should be created');
        $this->assertGreaterThan(0, filesize($filePath), 'Export file should not be empty');

        // ===== 2. VERIFY HEADERS MATCH IMPORT EXPECTATIONS =====
        $expectedHeaders = [
            'Título',
            'Descripción',
            'Fecha de Despacho',
            'Tipo de Usuario',
            'Tipo de Convenio',
            'Fecha Hora Máxima Pedido',
            'Activo',
            'Empresas Asociadas'
        ];

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

        // Assert headers match EXACTLY
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Export headers MUST match MenusImport expectations EXACTLY'
        );

        // Verify we have 8 headers
        $this->assertCount(8, $actualHeaders, 'Should have exactly 8 headers');

        // ===== 3. VERIFY ROW COUNT =====
        // We have 3 menus = 3 data rows + 1 header row = 4 total rows
        $lastRow = $sheet->getHighestRow();
        $this->assertEquals(4, $lastRow, 'Should have 4 rows (1 header + 3 data rows)');

        // ===== 4. VERIFY MENU 1 DATA =====
        $menu1 = $this->menus[1];

        // Column A: Título
        $this->assertEquals(
            $menu1->title,
            $sheet->getCell('A2')->getValue(),
            'Row 2: Menu title should match'
        );

        // Column B: Descripción
        $this->assertEquals(
            $menu1->description,
            $sheet->getCell('B2')->getValue(),
            'Row 2: Menu description should match'
        );

        // Column C: Fecha de Despacho
        $expectedDate = Carbon::parse($menu1->publication_date)->format('d/m/Y');
        $this->assertEquals(
            $expectedDate,
            $sheet->getCell('C2')->getValue(),
            'Row 2: Publication date should match'
        );

        // Column D: Tipo de Usuario
        $this->assertEquals(
            'Convenio',
            $sheet->getCell('D2')->getValue(),
            'Row 2: Role name should match'
        );

        // Column E: Tipo de Convenio
        $this->assertEquals(
            'Individual',
            $sheet->getCell('E2')->getValue(),
            'Row 2: Permission name should match'
        );

        // Column G: Activo
        $this->assertEquals(
            '1',
            $sheet->getCell('G2')->getValue(),
            'Row 2: Active should be 1'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test: CategoryMenu export file is generated successfully with correct headers and data
     */
    public function test_category_menu_export_generates_file_with_correct_headers_and_data(): void
    {
        // Collect category menu IDs
        $categoryMenuIds = collect($this->categoryMenus)->pluck('id');

        // Generate export file
        $filePath = $this->generateCategoryMenuExport($categoryMenuIds);

        // ===== 1. VERIFY FILE EXISTS =====
        $this->assertFileExists($filePath, 'Export file should be created');
        $this->assertGreaterThan(0, filesize($filePath), 'Export file should not be empty');

        // ===== 2. VERIFY HEADERS MATCH IMPORT EXPECTATIONS =====
        $expectedHeaders = [
            'Título del Menú',
            'Nombre de Categoría',
            'Mostrar Todos los Productos',
            'Orden de Visualización',
            'Categoría Obligatoria',
            'Activo',
            'Productos'
        ];

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

        // Assert headers match EXACTLY
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Export headers MUST match CategoryMenuImport expectations EXACTLY'
        );

        // Verify we have 7 headers
        $this->assertCount(7, $actualHeaders, 'Should have exactly 7 headers');

        // ===== 3. VERIFY ROW COUNT =====
        // We have 5 category menus = 5 data rows + 1 header row = 6 total rows
        $lastRow = $sheet->getHighestRow();
        $this->assertEquals(6, $lastRow, 'Should have 6 rows (1 header + 5 data rows)');

        // ===== 4. VERIFY CATEGORY MENU 1 DATA (Show All Products) =====
        $categoryMenu1 = $this->categoryMenus[0];

        // Column A: Título del Menú
        $this->assertEquals(
            'EXPORT TEST MENU 1',
            $sheet->getCell('A2')->getValue(),
            'Row 2: Menu title should match'
        );

        // Column B: Nombre de Categoría
        $this->assertEquals(
            'EXPORT CATEGORY 1',
            $sheet->getCell('B2')->getValue(),
            'Row 2: Category name should match'
        );

        // Column C: Mostrar Todos los Productos
        $this->assertEquals(
            '1',
            $sheet->getCell('C2')->getValue(),
            'Row 2: Show all products should be 1'
        );

        // Column D: Orden de Visualización
        $this->assertEquals(
            1,
            $sheet->getCell('D2')->getValue(),
            'Row 2: Display order should be 1'
        );

        // Column E: Categoría Obligatoria
        $this->assertEquals(
            '0',
            $sheet->getCell('E2')->getValue(),
            'Row 2: Mandatory should be 0'
        );

        // Column F: Activo
        $this->assertEquals(
            '1',
            $sheet->getCell('F2')->getValue(),
            'Row 2: Active should be 1'
        );

        // Column G: Productos (empty for show_all_products = true)
        $this->assertEmpty(
            $sheet->getCell('G2')->getValue(),
            'Row 2: Products should be empty when show_all_products is true'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test: CategoryMenu export includes products correctly for show_all_products = false
     */
    public function test_category_menu_export_includes_products_when_not_showing_all(): void
    {
        // Collect category menu IDs
        $categoryMenuIds = collect($this->categoryMenus)->pluck('id');

        // Generate export file
        $filePath = $this->generateCategoryMenuExport($categoryMenuIds);

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // ===== VERIFY CATEGORY MENU 3 (Show Specific Products - 2 products) =====
        // This is row 4 in Excel (1 header + 2 previous rows)
        $row = 4;

        // Column C: Mostrar Todos los Productos should be 0
        $this->assertEquals(
            '0',
            $sheet->getCell("C{$row}")->getValue(),
            "Row {$row}: Show all products should be 0"
        );

        // Column E: Categoría Obligatoria should be 1
        $this->assertEquals(
            '1',
            $sheet->getCell("E{$row}")->getValue(),
            "Row {$row}: Mandatory should be 1"
        );

        // Column G: Productos should contain EXPPROD001,EXPPROD002
        $productList = $sheet->getCell("G{$row}")->getValue();
        $this->assertNotEmpty($productList, "Row {$row}: Products should not be empty");
        $this->assertStringContainsString('EXPPROD001', $productList, "Row {$row}: Should contain EXPPROD001");
        $this->assertStringContainsString('EXPPROD002', $productList, "Row {$row}: Should contain EXPPROD002");

        // ===== VERIFY CATEGORY MENU 4 (Show Specific Products - 1 product) =====
        $row = 5;

        // Column C: Mostrar Todos los Productos should be 0
        $this->assertEquals(
            '0',
            $sheet->getCell("C{$row}")->getValue(),
            "Row {$row}: Show all products should be 0"
        );

        // Column G: Productos should contain only EXPPROD003
        $productList = $sheet->getCell("G{$row}")->getValue();
        $this->assertNotEmpty($productList, "Row {$row}: Products should not be empty");
        $this->assertEquals('EXPPROD003', $productList, "Row {$row}: Should contain only EXPPROD003");

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test: CategoryMenu export display order matches database values
     */
    public function test_category_menu_export_display_order_is_correct(): void
    {
        // Collect category menu IDs
        $categoryMenuIds = collect($this->categoryMenus)->pluck('id');

        // Generate export file
        $filePath = $this->generateCategoryMenuExport($categoryMenuIds);

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify display order for each row (Column D)
        $expectedOrders = [1, 2, 3, 4, 5];

        for ($i = 0; $i < 5; $i++) {
            $excelRow = $i + 2; // Skip header row
            $expectedOrder = $expectedOrders[$i];
            $actualOrder = $sheet->getCell("D{$excelRow}")->getValue();

            $this->assertEquals(
                $expectedOrder,
                $actualOrder,
                "Row {$excelRow}: Display order should be {$expectedOrder}"
            );
        }

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test: CategoryMenu export is_active field is exported correctly
     */
    public function test_category_menu_export_is_active_field_is_correct(): void
    {
        // Collect category menu IDs
        $categoryMenuIds = collect($this->categoryMenus)->pluck('id');

        // Generate export file
        $filePath = $this->generateCategoryMenuExport($categoryMenuIds);

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify is_active for each row (Column F)
        // First 4 are active (1), last one is not active (0)
        $expectedActiveValues = ['1', '1', '1', '1', '0'];

        for ($i = 0; $i < 5; $i++) {
            $excelRow = $i + 2; // Skip header row
            $expectedActive = $expectedActiveValues[$i];
            $actualActive = $sheet->getCell("F{$excelRow}")->getValue();

            $this->assertEquals(
                $expectedActive,
                $actualActive,
                "Row {$excelRow}: Active should be {$expectedActive}"
            );
        }

        // Clean up
        $this->cleanupTestFile($filePath);
    }
}