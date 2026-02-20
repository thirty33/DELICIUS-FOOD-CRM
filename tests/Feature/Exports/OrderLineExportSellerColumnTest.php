<?php

namespace Tests\Feature\Exports;

use App\Enums\OrderStatus;
use App\Exports\OrderLineExport;
use App\Imports\Concerns\OrderLineColumnDefinition;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\ExportProcess;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * T-SELLER-COL-01 to T-SELLER-COL-03
 *
 * Validates that the OrderLine export includes a "Vendedor" column that
 * exports the seller's nickname when one is assigned, or empty when not.
 */
class OrderLineExportSellerColumnTest extends TestCase
{
    use RefreshDatabase;

    protected PriceList $priceList;

    protected Company $company;

    protected Branch $branch;

    protected User $user;

    protected User $seller;

    protected Category $category;

    protected Product $product;

    protected Order $order;

    protected OrderLine $orderLine;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-02-20 10:00:00');

        $this->priceList = PriceList::create([
            'name' => 'Lista Estándar',
            'description' => 'Lista de precios estándar',
            'min_price_order' => 0,
        ]);

        $this->company = Company::create([
            'name' => 'EMPRESA SELLER TEST S.A.',
            'fantasy_name' => 'SELLER TEST',
            'company_code' => '22.222.222-2',
            'email' => 'seller@test.cl',
            'price_list_id' => $this->priceList->id,
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'branch_code' => '22.222.222-2MAIN',
            'fantasy_name' => 'SUCURSAL SELLER TEST',
            'min_price_order' => 0,
        ]);

        $this->seller = User::create([
            'name' => 'HERLINDA DELICIUS',
            'email' => 'herlinda@delicius.cl',
            'nickname' => 'HERLINDA.DELICIUS',
            'password' => Hash::make('Pass123'),
            'is_seller' => true,
        ]);

        $this->user = User::create([
            'name' => 'Cliente Con Vendedor',
            'email' => 'cliente@test.cl',
            'nickname' => 'CLIENTE.TEST',
            'password' => Hash::make('Pass123'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->category = Category::create([
            'name' => 'ENSALADAS',
            'code' => 'ENS',
            'active' => true,
        ]);

        $this->product = Product::create([
            'code' => 'PROD-SEL-001',
            'name' => 'Ensalada de Prueba',
            'description' => 'Producto para test vendedor',
            'price' => 100000,
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::PROCESSED->value,
            'dispatch_date' => '2026-02-25',
            'order_number' => 99001,
            'dispatch_cost' => 0,
        ]);

        $this->orderLine = OrderLine::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 100000,
            'unit_price_with_tax' => 119000,
            'partially_scheduled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // T-SELLER-COL-01
    // ---------------------------------------------------------------

    public function test_export_headers_include_vendedor_column(): void
    {
        $filePath = $this->generateExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcel($filePath)->getActiveSheet();

        $headers = $this->readHeaders($sheet);

        $this->assertContains(
            'Vendedor',
            $headers,
            'Export headers must include "Vendedor" column'
        );

        $this->cleanupFile($filePath);
    }

    // ---------------------------------------------------------------
    // T-SELLER-COL-02
    // ---------------------------------------------------------------

    public function test_exports_seller_nickname_when_user_has_seller_assigned(): void
    {
        $this->user->update(['seller_id' => $this->seller->id]);

        $filePath = $this->generateExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcel($filePath)->getActiveSheet();

        $cell = $sheet->getCell(OrderLineColumnDefinition::cell('vendedor', 2))->getValue();

        $this->assertEquals(
            'HERLINDA.DELICIUS',
            $cell,
            'Vendedor cell should contain the seller nickname'
        );

        $this->cleanupFile($filePath);
    }

    // ---------------------------------------------------------------
    // T-SELLER-COL-03
    // ---------------------------------------------------------------

    public function test_exports_empty_vendedor_when_user_has_no_seller(): void
    {
        // $this->user has seller_id = null (default from setUp)

        $filePath = $this->generateExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcel($filePath)->getActiveSheet();

        $cell = $sheet->getCell(OrderLineColumnDefinition::cell('vendedor', 2))->getValue();

        $this->assertEmpty(
            $cell,
            'Vendedor cell should be empty when user has no seller assigned'
        );

        $this->cleanupFile($filePath);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function generateExport(Collection $orderLineIds): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (! is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_ORDER_LINES,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $fileName = 'test-exports/seller-col-export-'.now()->format('Y-m-d-His').'.xlsx';
        $fullPath = storage_path('app/'.$fileName);

        $export = new OrderLineExport($orderLineIds, $exportProcess->id);
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath);

        return $fullPath;
    }

    private function loadExcel(string $filePath): Spreadsheet
    {
        $this->assertFileExists($filePath);

        return IOFactory::load($filePath);
    }

    private function readHeaders($sheet): array
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

    private function cleanupFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
