<?php

namespace Tests\Feature\Imports;

use App\Imports\Concerns\OrderLineColumnDefinition;
use App\Imports\OrderLinesImport;
use App\Models\ImportProcess;
use App\Models\User;
use Database\Seeders\OrderLinesImportTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * T-SELLER-COL-04 and T-SELLER-COL-05
 *
 * Validates that the OrderLine importer:
 *   - accepts the "Vendedor" column without error
 *   - ignores its value and does not modify users.seller_id
 */
class OrderLineImportSellerColumnTest extends TestCase
{
    use ConfiguresImportTests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureImportTest();
        $this->seed(OrderLinesImportTestSeeder::class);
    }

    // ---------------------------------------------------------------
    // T-SELLER-COL-04
    // ---------------------------------------------------------------

    public function test_heading_map_includes_vendedor_so_importer_accepts_the_column(): void
    {
        // The HEADING_MAP must declare the 'vendedor' key so Maatwebsite Excel
        // does not treat it as an unknown/unexpected column during import.
        $this->assertArrayHasKey(
            'vendedor',
            OrderLineColumnDefinition::HEADING_MAP,
            'OrderLineColumnDefinition::HEADING_MAP must include "vendedor" key'
        );
    }

    // ---------------------------------------------------------------
    // T-SELLER-COL-05
    // ---------------------------------------------------------------

    public function test_importer_ignores_vendedor_value_and_does_not_modify_seller_id(): void
    {
        Storage::fake('s3');

        // The seeder creates user RECEPCION@ALIACE.CL with seller_id = null.
        $user = User::where('email', 'RECEPCION@ALIACE.CL')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->seller_id, 'Precondition: user should have no seller assigned');

        // vendedor column maps to an ignored field (prefixed with _), so it
        // must not write back to any model attribute.
        $this->assertStringStartsWith(
            '_',
            OrderLineColumnDefinition::HEADING_MAP['vendedor'],
            'The HEADING_MAP entry for "vendedor" must use the _ prefix to signal the importer to ignore it'
        );

        // Generate fixture with the Vendedor column filled in.
        $fixture = $this->generateFixtureWithVendedor('HERLINDA.DELICIUS');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(new OrderLinesImport($importProcess->id), $fixture);

        $user->refresh();
        $this->assertNull(
            $user->seller_id,
            'seller_id must remain null after import — the Vendedor column is read-only'
        );

        if (file_exists($fixture)) {
            unlink($fixture);
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Generate an import fixture that includes the Vendedor column with the
     * given seller nickname. All other columns match the OrderLinesImportTestSeeder data.
     */
    private function generateFixtureWithVendedor(string $sellerNickname): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = OrderLineColumnDefinition::headers();
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }

        // Find the position of the Vendedor column.
        $vendedorIndex = array_search('Vendedor', $headers);

        // Data matching OrderLinesImportTestSeeder (order 20251103510024, 1 product row).
        $row = [
            null,
            '20251103510024',
            'Procesado',
            '03/11/2025',
            '12/11/2025',
            '76.505.808-2',
            'ALIMENTOS Y ACEITES SPA',
            '76.505.808-2',
            'CONVENIO ALIACE',
            'RECEPCION@ALIACE.CL',
            '',
            '',
            'MINI ENSALADAS DE ACOMPAÑAMIENTO',
            'ACM00000043',
            '',
            'ACM - MINI ENSALADA ACEITUNAS Y HUEVO DURO',
            1,
            4,
            4.76,
            4,
            4.76,
            0,
        ];

        // Insert the Vendedor value at the correct column position.
        // If vendedorIndex is false (column not in definition yet), the test
        // will fail at the HEADING_MAP assertion before reaching this point.
        if ($vendedorIndex !== false) {
            array_splice($row, $vendedorIndex, 0, [$sellerNickname]);
        }

        foreach ($row as $colIndex => $value) {
            $sheet->setCellValueByColumnAndRow($colIndex + 1, 2, $value);
        }

        $filePath = base_path('tests/Fixtures/test_seller_column_import.xlsx');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);

        return $filePath;
    }
}
