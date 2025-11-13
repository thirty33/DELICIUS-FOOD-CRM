<?php

namespace Tests\Helpers;

use App\Exports\AdvanceOrderReportExport;
use App\Models\ExportProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Helper trait for testing Advance Order Consolidated Reports
 *
 * Provides reusable functions for:
 * - Generating consolidated reports from advance orders
 * - Reading and parsing Excel files
 * - Validating report data (date ranges, product data, calculations)
 */
trait AdvanceOrderReportTestHelper
{
    /**
     * Generate consolidated report for given advance order IDs
     *
     * @param array $advanceOrderIds Array of AdvanceOrder IDs
     * @param bool $showExcludedCompanies Show excluded companies columns
     * @param bool $showAllAdelantos Show all adelanto columns
     * @param bool $showTotalElaborado Show total elaborado column
     * @param bool $showSobrantes Show sobrantes column
     * @return string Path to generated Excel file
     */
    protected function generateConsolidatedReport(
        array $advanceOrderIds,
        bool $showExcludedCompanies = true,
        bool $showAllAdelantos = true,
        bool $showTotalElaborado = true,
        bool $showSobrantes = true
    ): string {
        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate filename
        $idsString = implode('-', $advanceOrderIds);
        $fileName = 'test-exports/advance-orders-' . $idsString . '-' . now()->format('Y-m-d-His') . '.xlsx';

        // Create the export instance
        $export = new AdvanceOrderReportExport(
            $advanceOrderIds,
            $showExcludedCompanies,
            $showAllAdelantos,
            $showTotalElaborado,
            $showSobrantes
        );

        // Get full path
        $fullPath = storage_path('app/' . $fileName);

        // Use Excel::raw() to bypass queuing and generate file immediately
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        // Write the content to file
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath, "Excel file should be created at {$fullPath}");

        return $fullPath;
    }

    /**
     * Load Excel file and return Spreadsheet object
     *
     * @param string $filePath Path to Excel file
     * @return Spreadsheet
     */
    protected function loadExcelFile(string $filePath): Spreadsheet
    {
        $this->assertFileExists($filePath, "Excel file should exist at {$filePath}");

        return IOFactory::load($filePath);
    }

    /**
     * Extract date range from Excel report header
     *
     * The export structure is:
     * Row 1: Headers (Categoría, Código de Producto, etc.) - from headings()
     * Row 2: "RANGO DE FECHAS DE DESPACHO:" - from collection()
     * Row 3: "Desde: DD/MM/YYYY - Hasta: DD/MM/YYYY" - from collection()
     *
     * @param Spreadsheet $spreadsheet
     * @return array ['from' => 'DD/MM/YYYY', 'to' => 'DD/MM/YYYY']
     */
    protected function extractDateRangeFromReport(Spreadsheet $spreadsheet): array
    {
        $worksheet = $spreadsheet->getActiveSheet();

        // Row 2 should contain "RANGO DE FECHAS DE DESPACHO:"
        $row2Value = $worksheet->getCell('A2')->getValue();
        $this->assertStringContainsString(
            'RANGO DE FECHAS DE DESPACHO',
            $row2Value,
            'Row 2 should contain date range header'
        );

        // Row 3 should contain "Desde: DD/MM/YYYY - Hasta: DD/MM/YYYY"
        $row3Value = $worksheet->getCell('A3')->getValue();

        // Parse dates using regex
        $pattern = '/Desde:\s*(\d{2}\/\d{2}\/\d{4})\s*-\s*Hasta:\s*(\d{2}\/\d{2}\/\d{4})/';
        preg_match($pattern, $row3Value, $matches);

        $this->assertCount(3, $matches, "Should extract 'from' and 'to' dates from row 3: {$row3Value}");

        return [
            'from' => $matches[1],
            'to' => $matches[2]
        ];
    }

    /**
     * Validate that date range matches expected dispatch dates from advance orders
     *
     * @param array $advanceOrderIds Array of AdvanceOrder IDs
     * @param array $extractedRange ['from' => 'DD/MM/YYYY', 'to' => 'DD/MM/YYYY']
     */
    protected function validateDateRangeMatchesAdvanceOrders(array $advanceOrderIds, array $extractedRange): void
    {
        // Get all dispatch dates from advance orders' order lines
        $dispatchDates = \DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->join('orders', 'order_lines.order_id', '=', 'orders.id')
            ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds)
            ->distinct()
            ->pluck('orders.dispatch_date')
            ->map(fn($date) => \Carbon\Carbon::parse($date))
            ->sort();

        $this->assertGreaterThan(0, $dispatchDates->count(), 'Should have at least one dispatch date');

        // Expected range
        $expectedFrom = $dispatchDates->first()->format('d/m/Y');
        $expectedTo = $dispatchDates->last()->format('d/m/Y');

        // Validate extracted range
        $this->assertEquals(
            $expectedFrom,
            $extractedRange['from'],
            "Report 'from' date should match earliest dispatch date"
        );

        $this->assertEquals(
            $expectedTo,
            $extractedRange['to'],
            "Report 'to' date should match latest dispatch date"
        );
    }

    /**
     * Find the header row in the Excel sheet
     *
     * The header row contains columns like "Categoría", "Código de Producto", etc.
     *
     * @param Spreadsheet $spreadsheet
     * @return int Row number (1-indexed) where headers are found
     */
    protected function findHeaderRow(Spreadsheet $spreadsheet): int
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $maxRows = 20; // Search first 20 rows

        for ($row = 1; $row <= $maxRows; $row++) {
            $cellValue = $worksheet->getCell('A' . $row)->getValue();

            if ($cellValue === 'Categoría') {
                return $row;
            }
        }

        $this->fail('Could not find header row with "Categoría" in column A');
        return 0; // This line is never reached, but satisfies static analysis
    }

    /**
     * Extract product data rows from Excel sheet
     *
     * @param Spreadsheet $spreadsheet
     * @return array Array of rows, each row is an associative array with column headers as keys
     */
    protected function extractProductDataRows(Spreadsheet $spreadsheet): array
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $headerRow = $this->findHeaderRow($spreadsheet);

        // Get headers from header row
        $headers = [];
        $col = 'A';
        while (true) {
            $headerValue = $worksheet->getCell($col . $headerRow)->getValue();
            if ($headerValue === null || $headerValue === '') {
                break;
            }
            $headers[] = $headerValue;
            $col++;
        }

        $this->assertGreaterThan(0, count($headers), 'Should have at least one header column');

        // Extract data rows (start from row after header)
        $dataRows = [];
        $currentRow = $headerRow + 1;
        $maxRows = 1000; // Safety limit

        for ($i = 0; $i < $maxRows; $i++) {
            $rowData = [];
            $firstCellValue = $worksheet->getCell('A' . $currentRow)->getValue();

            // Stop if we hit empty row or "TOTAL" row
            if ($firstCellValue === null || $firstCellValue === '' || $firstCellValue === 'TOTAL') {
                break;
            }

            // Read all columns for this row
            foreach ($headers as $index => $header) {
                $colLetter = chr(65 + $index); // A=65, B=66, etc.
                $cellValue = $worksheet->getCell($colLetter . $currentRow)->getValue();
                $rowData[$header] = $cellValue;
            }

            $dataRows[] = $rowData;
            $currentRow++;
        }

        return $dataRows;
    }

    /**
     * Validate that product appears in report with expected data
     *
     * @param array $productDataRows Array of product rows from Excel
     * @param string $productCode Expected product code
     * @param int $expectedTotalPedidos Expected value in "TOTAL PEDIDOS" column
     * @param string $message Optional assertion message
     */
    protected function assertProductInReport(
        array $productDataRows,
        string $productCode,
        int $expectedTotalPedidos,
        string $message = ''
    ): void {
        $found = false;

        foreach ($productDataRows as $row) {
            if (isset($row['Código de Producto']) && $row['Código de Producto'] === $productCode) {
                $found = true;

                $actualTotal = $row['TOTAL PEDIDOS'] ?? null;
                $this->assertEquals(
                    $expectedTotalPedidos,
                    $actualTotal,
                    $message ?: "Product {$productCode} should have TOTAL PEDIDOS = {$expectedTotalPedidos}"
                );

                break;
            }
        }

        $this->assertTrue($found, "Product {$productCode} should be present in report");
    }

    /**
     * Validate OP columns (ADELANTO and ELABORAR) in consolidated report
     *
     * This function validates:
     * 1. Correct number of ADELANTO columns (1 per OP)
     * 2. Correct number of ELABORAR columns (1 per OP)
     * 3. Values in those columns match database data
     *
     * Column detection is dynamic to handle variable company columns.
     *
     * @param Spreadsheet $spreadsheet
     * @param array $advanceOrderIds Array of AdvanceOrder IDs in chronological order
     * @return void
     */
    protected function validateOpColumnsInReport(Spreadsheet $spreadsheet, array $advanceOrderIds): void
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $headerRow = $this->findHeaderRow($spreadsheet);

        // Parse all headers to find ADELANTO and ELABORAR columns
        $adelantoColumns = [];
        $elaborarColumns = [];
        $col = 'A';
        $colIndex = 0;

        while (true) {
            $headerValue = $worksheet->getCell($col . $headerRow)->getValue();
            if ($headerValue === null || $headerValue === '') {
                break;
            }

            // Match ADELANTO columns (e.g., "ADELANTO OP #1", "ADELANTO OP #2")
            if (preg_match('/^ADELANTO OP #(\d+)$/i', $headerValue, $matches)) {
                $opNumber = (int) $matches[1];
                $adelantoColumns[$opNumber] = [
                    'column' => $col,
                    'index' => $colIndex,
                    'header' => $headerValue
                ];
            }

            // Match ELABORAR columns (e.g., "ELABORAR OP #1", "ELABORAR OP #2")
            if (preg_match('/^ELABORAR OP #(\d+)$/i', $headerValue, $matches)) {
                $opNumber = (int) $matches[1];
                $elaborarColumns[$opNumber] = [
                    'column' => $col,
                    'index' => $colIndex,
                    'header' => $headerValue
                ];
            }

            $col++;
            $colIndex++;
        }

        // Validate column counts
        $expectedOpCount = count($advanceOrderIds);
        $this->assertCount(
            $expectedOpCount,
            $adelantoColumns,
            "Should have {$expectedOpCount} ADELANTO columns (one per OP)"
        );
        $this->assertCount(
            $expectedOpCount,
            $elaborarColumns,
            "Should have {$expectedOpCount} ELABORAR columns (one per OP)"
        );

        // Validate that columns exist for each OP number
        for ($opNumber = 1; $opNumber <= $expectedOpCount; $opNumber++) {
            $this->assertArrayHasKey(
                $opNumber,
                $adelantoColumns,
                "Should have ADELANTO column for OP #{$opNumber}"
            );
            $this->assertArrayHasKey(
                $opNumber,
                $elaborarColumns,
                "Should have ELABORAR column for OP #{$opNumber}"
            );
        }

        // Get expected values from database for each OP and product
        $expectedValues = $this->getExpectedOpColumnValues($advanceOrderIds);

        // Extract product data rows
        $productDataRows = $this->extractProductDataRows($spreadsheet);

        // Validate values for each product and each OP
        foreach ($productDataRows as $row) {
            $productCode = $row['Código de Producto'] ?? null;
            if ($productCode === null) {
                continue;
            }

            // Check if this product has expected values in any OP
            if (!isset($expectedValues[$productCode])) {
                continue;
            }

            $productExpectedValues = $expectedValues[$productCode];

            // Validate each OP's ADELANTO and ELABORAR values for this product
            foreach ($productExpectedValues as $opNumber => $values) {
                $adelantoCol = $adelantoColumns[$opNumber]['column'];
                $elaborarCol = $elaborarColumns[$opNumber]['column'];

                $actualAdelanto = $row["ADELANTO OP #{$opNumber}"] ?? null;
                $actualElaborar = $row["ELABORAR OP #{$opNumber}"] ?? null;

                $this->assertEquals(
                    $values['adelanto'],
                    $actualAdelanto,
                    "Product {$productCode} - ADELANTO OP #{$opNumber} should be {$values['adelanto']}"
                );

                $this->assertEquals(
                    $values['elaborar'],
                    $actualElaborar,
                    "Product {$productCode} - ELABORAR OP #{$opNumber} should be {$values['elaborar']}"
                );
            }
        }
    }

    /**
     * Get expected ADELANTO and ELABORAR values from database for each OP
     *
     * @param array $advanceOrderIds Array of AdvanceOrder IDs in chronological order
     * @return array Nested array [productCode => [opNumber => ['adelanto' => value, 'elaborar' => value]]]
     */
    private function getExpectedOpColumnValues(array $advanceOrderIds): array
    {
        $expectedValues = [];

        foreach ($advanceOrderIds as $index => $advanceOrderId) {
            $opNumber = $index + 1;

            // Get all order lines for this advance order
            $orderLines = \DB::table('advance_order_order_lines')
                ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
                ->join('products', 'order_lines.product_id', '=', 'products.id')
                ->where('advance_order_order_lines.advance_order_id', $advanceOrderId)
                ->select(
                    'products.code as product_code',
                    \DB::raw('SUM(order_lines.quantity) as total_quantity')
                )
                ->groupBy('products.code')
                ->get();

            foreach ($orderLines as $line) {
                if (!isset($expectedValues[$line->product_code])) {
                    $expectedValues[$line->product_code] = [];
                }

                $expectedValues[$line->product_code][$opNumber] = [
                    'adelanto' => (int) $line->total_quantity,
                    'elaborar' => (int) $line->total_quantity
                ];
            }
        }

        return $expectedValues;
    }

    /**
     * Clean up generated test files
     *
     * @param string $filePath Path to file to delete
     */
    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
