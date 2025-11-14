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
     * @param string|null $customFileName Custom filename (without extension). If null, uses default naming with OP IDs
     * @return string Path to generated Excel file
     */
    protected function generateConsolidatedReport(
        array $advanceOrderIds,
        bool $showExcludedCompanies = true,
        bool $showAllAdelantos = true,
        bool $showTotalElaborado = true,
        bool $showSobrantes = true,
        ?string $customFileName = null
    ): string {
        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate filename
        if ($customFileName) {
            // Use custom filename (without extension, will be added below)
            $fileName = 'test-exports/' . $customFileName . '-' . now()->format('Y-m-d-His') . '.xlsx';
        } else {
            // Default behavior: use OP IDs
            $idsString = implode('-', $advanceOrderIds);
            $fileName = 'test-exports/advance-orders-' . $idsString . '-' . now()->format('Y-m-d-His') . '.xlsx';
        }

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

        // DUMP: Show generated file info
        dump([
            'GENERATED FILE INFO',
            'Full Path' => $fullPath,
            'File Name' => $fileName,
            'File Exists' => file_exists($fullPath) ? 'YES' : 'NO',
            'File Size' => file_exists($fullPath) ? filesize($fullPath) . ' bytes' : 'N/A',
        ]);

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

            // Match ADELANTO columns
            // Format: "ADELANTO INICIAL" (OP #1), "ADELANTO 2", "ADELANTO 3", etc.
            if ($headerValue === 'ADELANTO INICIAL') {
                $adelantoColumns[1] = [
                    'column' => $col,
                    'index' => $colIndex,
                    'header' => $headerValue
                ];
            } elseif (preg_match('/^ADELANTO (\d+)$/i', $headerValue, $matches)) {
                $opNumber = (int) $matches[1];
                $adelantoColumns[$opNumber] = [
                    'column' => $col,
                    'index' => $colIndex,
                    'header' => $headerValue
                ];
            }

            // Match ELABORAR columns
            // Format: "ELABORAR 1", "ELABORAR 2", "ELABORAR 3", etc.
            if (preg_match('/^ELABORAR (\d+)$/i', $headerValue, $matches)) {
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
                $adelantoHeaderName = $adelantoColumns[$opNumber]['header'];
                $elaborarHeaderName = $elaborarColumns[$opNumber]['header'];

                $actualAdelanto = $row[$adelantoHeaderName] ?? null;
                $actualElaborar = $row[$elaborarHeaderName] ?? null;

                $this->assertEquals(
                    $values['adelanto'],
                    $actualAdelanto,
                    "Product {$productCode} - {$adelantoHeaderName} should be {$values['adelanto']}"
                );

                $this->assertEquals(
                    $values['elaborar'],
                    $actualElaborar,
                    "Product {$productCode} - {$elaborarHeaderName} should be {$values['elaborar']}"
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
     * Validate TOTAL ELABORADO and SOBRANTES columns in consolidated report
     *
     * This function validates that:
     * 1. TOTAL ELABORADO = sum of all ELABORAR columns for each product
     * 2. SOBRANTES = TOTAL ELABORADO - TOTAL PEDIDOS
     *
     * @param Spreadsheet $spreadsheet
     * @param array $advanceOrderIds Array of AdvanceOrder IDs
     * @return void
     */
    protected function validateTotalElaboradoAndSobrantesColumns(Spreadsheet $spreadsheet, array $advanceOrderIds): void
    {
        $productDataRows = $this->extractProductDataRows($spreadsheet);

        foreach ($productDataRows as $row) {
            $productCode = $row['Código de Producto'] ?? null;
            if ($productCode === null) {
                continue;
            }

            // Calculate expected TOTAL ELABORADO (sum of all ELABORAR columns)
            $expectedTotalElaborado = 0;
            for ($opNumber = 1; $opNumber <= count($advanceOrderIds); $opNumber++) {
                $elaborarHeader = ($opNumber === 1) ? "ELABORAR 1" : "ELABORAR {$opNumber}";
                $elaborarValue = $row[$elaborarHeader] ?? 0;
                $expectedTotalElaborado += (int) $elaborarValue;
            }

            // Get actual TOTAL ELABORADO from report
            $actualTotalElaborado = $row['TOTAL ELABORADO'] ?? null;

            $this->assertEquals(
                $expectedTotalElaborado,
                $actualTotalElaborado,
                "Product {$productCode} - TOTAL ELABORADO should be {$expectedTotalElaborado} (sum of all ELABORAR columns)"
            );

            // Calculate expected SOBRANTES (TOTAL ELABORADO - TOTAL PEDIDOS)
            $totalPedidos = $row['TOTAL PEDIDOS'] ?? 0;
            $expectedSobrantes = $expectedTotalElaborado - (int) $totalPedidos;

            // Get actual SOBRANTES from report
            $actualSobrantes = $row['SOBRANTES'] ?? null;

            $this->assertEquals(
                $expectedSobrantes,
                $actualSobrantes,
                "Product {$productCode} - SOBRANTES should be {$expectedSobrantes} (TOTAL ELABORADO - TOTAL PEDIDOS)"
            );
        }
    }

    /**
     * Validate that discriminated company columns appear in the report
     *
     * This function validates that companies with exclude_from_consolidated_report = false
     * have their own columns in the report.
     *
     * @param Spreadsheet $spreadsheet
     * @param array $expectedCompanyNames Array of company fantasy names that should appear
     * @return void
     */
    protected function validateDiscriminatedCompanyColumns(Spreadsheet $spreadsheet, array $expectedCompanyNames): void
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $headerRow = $this->findHeaderRow($spreadsheet);

        // Get all headers
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

        // Validate that each expected company has a column
        foreach ($expectedCompanyNames as $companyName) {
            $found = false;
            foreach ($headers as $header) {
                if (stripos($header, $companyName) !== false) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue(
                $found,
                "Company '{$companyName}' should have a discriminated column in the report"
            );
        }
    }

    /**
     * Validate TOTAL PEDIDOS column values match sum of all order quantities
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to validate
     * @param array $advanceOrderIds Array of advance order IDs
     */
    protected function validateTotalPedidosColumn(Spreadsheet $spreadsheet, array $advanceOrderIds): void
    {
        $sheet = $spreadsheet->getActiveSheet();

        // Find TOTAL PEDIDOS column
        $headers = [];
        $totalPedidosColIndex = null;

        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                $headers[$cell->getColumn()] = $value;

                if ($value === 'TOTAL PEDIDOS') {
                    $totalPedidosColIndex = $cell->getColumn();
                    break 2;
                }
            }
        }

        $this->assertNotNull($totalPedidosColIndex, 'TOTAL PEDIDOS column not found in report');

        // Get expected values - sum ALL UNIQUE order quantities (count each order_line only ONCE)
        // Query unique order_lines that appear in ANY of the selected advance orders
        $expectedValues = \DB::table('order_lines')
            ->join('orders', 'order_lines.order_id', '=', 'orders.id')
            ->join('products', 'order_lines.product_id', '=', 'products.id')
            ->whereExists(function ($query) use ($advanceOrderIds) {
                $query->select(\DB::raw(1))
                    ->from('advance_order_order_lines')
                    ->whereColumn('advance_order_order_lines.order_line_id', 'order_lines.id')
                    ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds);
            })
            ->select('products.code as product_code', \DB::raw('SUM(order_lines.quantity) as total_quantity'))
            ->groupBy('products.code')
            ->pluck('total_quantity', 'product_code');

        // Validate values in Excel
        foreach ($sheet->getRowIterator(2) as $row) {
            $productCode = $sheet->getCell('A' . $row->getRowIndex())->getValue();

            // Stop at empty row
            if (empty($productCode)) {
                break;
            }

            $actualValue = $sheet->getCell($totalPedidosColIndex . $row->getRowIndex())->getValue();

            if (isset($expectedValues[$productCode])) {
                $this->assertEquals(
                    $expectedValues[$productCode],
                    $actualValue,
                    "TOTAL PEDIDOS for product {$productCode} should be {$expectedValues[$productCode]}, but got {$actualValue}"
                );
            }
        }
    }

    /**
     * Validate discriminated company column values
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to validate
     * @param array $advanceOrderIds Array of advance order IDs
     * @param array $expectedCompanyNames Array of company names that should be discriminated
     */
    protected function validateDiscriminatedCompanyValues(Spreadsheet $spreadsheet, array $advanceOrderIds, array $expectedCompanyNames): void
    {
        $sheet = $spreadsheet->getActiveSheet();

        // Find company columns
        $headers = [];
        $companyColumns = [];

        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                $headers[$cell->getColumn()] = $value;

                // Check if this header contains any expected company name
                foreach ($expectedCompanyNames as $companyName) {
                    if (stripos($value, $companyName) !== false) {
                        $companyColumns[$companyName] = $cell->getColumn();
                        break;
                    }
                }
            }
        }

        $this->assertCount(
            count($expectedCompanyNames),
            $companyColumns,
            'Not all discriminated company columns were found'
        );

        // Get expected values per company from database
        foreach ($expectedCompanyNames as $companyName) {
            $columnIndex = $companyColumns[$companyName];

            // Query for this specific company's quantities (UNIQUE order_lines only)
            // Count each order_line only ONCE, even if it appears in multiple advance orders
            $expectedValues = \DB::table('order_lines')
                ->join('orders', 'order_lines.order_id', '=', 'orders.id')
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->join('companies', 'users.company_id', '=', 'companies.id')
                ->join('products', 'order_lines.product_id', '=', 'products.id')
                ->whereExists(function ($query) use ($advanceOrderIds) {
                    $query->select(\DB::raw(1))
                        ->from('advance_order_order_lines')
                        ->whereColumn('advance_order_order_lines.order_line_id', 'order_lines.id')
                        ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds);
                })
                ->where('companies.name', $companyName)
                ->where('companies.exclude_from_consolidated_report', true)
                ->select('products.code as product_code', \DB::raw('SUM(order_lines.quantity) as total_quantity'))
                ->groupBy('products.code')
                ->pluck('total_quantity', 'product_code');

            // Validate values in Excel
            foreach ($sheet->getRowIterator(2) as $row) {
                $productCode = $sheet->getCell('A' . $row->getRowIndex())->getValue();

                // Stop at empty row
                if (empty($productCode)) {
                    break;
                }

                $actualValue = $sheet->getCell($columnIndex . $row->getRowIndex())->getValue();

                if (isset($expectedValues[$productCode])) {
                    $this->assertEquals(
                        $expectedValues[$productCode],
                        $actualValue,
                        "{$companyName} column for product {$productCode} should be {$expectedValues[$productCode]}, but got {$actualValue}"
                    );
                }
            }
        }
    }

    /**
     * Validate that TOTAL PEDIDOS equals sum of discriminated + consolidated companies
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to validate
     * @param array $advanceOrderIds Array of advance order IDs
     * @param array $discriminatedCompanyNames Array of discriminated company names
     */
    protected function validateTotalPedidosEqualsCompanySum(Spreadsheet $spreadsheet, array $advanceOrderIds, array $discriminatedCompanyNames): void
    {
        $sheet = $spreadsheet->getActiveSheet();

        // Find TOTAL PEDIDOS column and company columns
        $totalPedidosColIndex = null;
        $companyColumns = [];

        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();

                if ($value === 'TOTAL PEDIDOS') {
                    $totalPedidosColIndex = $cell->getColumn();
                }

                foreach ($discriminatedCompanyNames as $companyName) {
                    if (stripos($value, $companyName) !== false) {
                        $companyColumns[$companyName] = $cell->getColumn();
                    }
                }
            }
        }

        $this->assertNotNull($totalPedidosColIndex, 'TOTAL PEDIDOS column not found');
        $this->assertCount(
            count($discriminatedCompanyNames),
            $companyColumns,
            'Not all discriminated company columns were found'
        );

        // Get consolidated company quantities (companies NOT discriminated)
        // Count each order_line only ONCE, even if it appears in multiple advance orders
        $consolidatedQuantities = \DB::table('order_lines')
            ->join('orders', 'order_lines.order_id', '=', 'orders.id')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->join('products', 'order_lines.product_id', '=', 'products.id')
            ->whereExists(function ($query) use ($advanceOrderIds) {
                $query->select(\DB::raw(1))
                    ->from('advance_order_order_lines')
                    ->whereColumn('advance_order_order_lines.order_line_id', 'order_lines.id')
                    ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds);
            })
            ->where('companies.exclude_from_consolidated_report', false)
            ->select('products.code as product_code', \DB::raw('SUM(order_lines.quantity) as total_quantity'))
            ->groupBy('products.code')
            ->pluck('total_quantity', 'product_code');

        // Validate for each product row
        foreach ($sheet->getRowIterator(2) as $row) {
            $productCode = $sheet->getCell('A' . $row->getRowIndex())->getValue();

            // Stop at empty row
            if (empty($productCode)) {
                break;
            }

            $totalPedidos = $sheet->getCell($totalPedidosColIndex . $row->getRowIndex())->getValue();

            // Sum discriminated company columns
            $discriminatedSum = 0;
            foreach ($companyColumns as $companyName => $columnIndex) {
                $value = $sheet->getCell($columnIndex . $row->getRowIndex())->getValue();
                $discriminatedSum += $value;
            }

            // Add consolidated company quantity
            $consolidatedQuantity = $consolidatedQuantities[$productCode] ?? 0;
            $expectedTotal = $discriminatedSum + $consolidatedQuantity;

            $this->assertEquals(
                $expectedTotal,
                $totalPedidos,
                "Product {$productCode}: TOTAL PEDIDOS ({$totalPedidos}) should equal discriminated companies ({$discriminatedSum}) + consolidated companies ({$consolidatedQuantity}) = {$expectedTotal}"
            );
        }
    }

    /**
     * Validate report with hardcoded expected values (no database queries)
     *
     * This validates against KNOWN correct values, not against database queries
     * that might contain the same bugs as the report generation logic.
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to validate
     * @param array $expectedValues Hardcoded expected values
     */
    protected function validateReportWithHardcodedValues(Spreadsheet $spreadsheet, array $expectedValues): void
    {
        $sheet = $spreadsheet->getActiveSheet();

        // Find the header row (it's NOT row 1 - there are metadata rows before it)
        $headerRow = $this->findHeaderRow($spreadsheet);

        // Find column indices from the HEADER row
        $totalPedidosCol = null;
        $company2Col = null;
        $company3Col = null;
        $productCodeCol = null;

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'TOTAL PEDIDOS') {
                $totalPedidosCol = $col;
            }
            if (stripos($headerValue, 'Company 2') !== false || $headerValue === 'COMPANY 2') {
                $company2Col = $col;
            }
            if (stripos($headerValue, 'Company 3') !== false || $headerValue === 'COMPANY 3') {
                $company3Col = $col;
            }
            if ($headerValue === 'Código de Producto') {
                $productCodeCol = $col;
            }

            $col++;
        }

        $this->assertNotNull($headerRow, 'Header row not found');
        $this->assertNotNull($totalPedidosCol, 'TOTAL PEDIDOS column not found');
        $this->assertNotNull($company2Col, 'Company 2 column not found');
        $this->assertNotNull($company3Col, 'Company 3 column not found');
        $this->assertNotNull($productCodeCol, 'Código de Producto column not found');

        dump("Header row found at: {$headerRow}");
        dump("TOTAL PEDIDOS column: {$totalPedidosCol}");
        dump("Company 2 column: {$company2Col}");
        dump("Company 3 column: {$company3Col}");
        dump("Product Code column: {$productCodeCol}");

        // Validate each product row (starting AFTER header row)
        $validatedProducts = 0;

        for ($rowIndex = $headerRow + 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
            $productCode = $sheet->getCell($productCodeCol . $rowIndex)->getValue();

            // Skip empty rows
            if (empty($productCode)) {
                continue;
            }

            // Check if we have expected values for this product
            if (!isset($expectedValues[$productCode])) {
                continue;
            }

            dump("Validating row {$rowIndex}: {$productCode}");

            $expected = $expectedValues[$productCode];

            // Validate TOTAL PEDIDOS
            $actualTotalPedidos = $sheet->getCell($totalPedidosCol . $rowIndex)->getValue();
            dump("  TOTAL PEDIDOS: expected={$expected['total_pedidos']}, actual={$actualTotalPedidos}");

            $this->assertEquals(
                $expected['total_pedidos'],
                $actualTotalPedidos,
                "Product {$productCode}: TOTAL PEDIDOS should be {$expected['total_pedidos']}, but got {$actualTotalPedidos}"
            );

            // Validate Company 2
            $actualCompany2 = $sheet->getCell($company2Col . $rowIndex)->getValue();
            dump("  Company 2: expected={$expected['company_2']}, actual={$actualCompany2}");

            $this->assertEquals(
                $expected['company_2'],
                $actualCompany2,
                "Product {$productCode}: Company 2 should be {$expected['company_2']}, but got {$actualCompany2}"
            );

            // Validate Company 3
            $actualCompany3 = $sheet->getCell($company3Col . $rowIndex)->getValue();
            dump("  Company 3: expected={$expected['company_3']}, actual={$actualCompany3}");

            $this->assertEquals(
                $expected['company_3'],
                $actualCompany3,
                "Product {$productCode}: Company 3 should be {$expected['company_3']}, but got {$actualCompany3}"
            );

            // Validate that TOTAL >= Company 2 + Company 3 (math sanity check)
            $discriminatedSum = $actualCompany2 + $actualCompany3;
            $this->assertGreaterThanOrEqual(
                $discriminatedSum,
                $actualTotalPedidos,
                "Product {$productCode}: TOTAL PEDIDOS ({$actualTotalPedidos}) cannot be less than discriminated companies sum ({$discriminatedSum})"
            );

            $validatedProducts++;
        }

        // Ensure we validated all expected products
        $this->assertEquals(
            count($expectedValues),
            $validatedProducts,
            "Should have validated " . count($expectedValues) . " products, but only validated {$validatedProducts}"
        );
    }

    /**
     * Validate production values (ADELANTO, ELABORAR, TOTAL ELABORADO, SOBRANTES) in consolidated report
     *
     * Expected values structure:
     * [
     *     'PRODUCT_A' => [
     *         'adelanto_inicial' => 50,
     *         'elaborar_1' => 50,
     *         'adelanto_2' => 0,
     *         'elaborar_2' => 90,
     *         'total_elaborado' => 140,
     *         'sobrantes' => 0,
     *     ],
     * ]
     */
    protected function validateProductionValues(Spreadsheet $spreadsheet, array $expectedValues): void
    {
        $sheet = $spreadsheet->getActiveSheet();

        // Find the header row
        $headerRow = $this->findHeaderRow($spreadsheet);

        dump("=== VALIDATING PRODUCTION VALUES ===");
        dump("Header row: {$headerRow}");

        // Find column indices from header
        $adelantoInicialCol = null;
        $elaborar1Col = null;
        $adelanto2Col = null;
        $elaborar2Col = null;
        $totalElaboradoCol = null;
        $sobrantesCol = null;
        $productCodeCol = null;

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'ADELANTO INICIAL') {
                $adelantoInicialCol = $col;
            } elseif ($headerValue === 'ELABORAR 1') {
                $elaborar1Col = $col;
            } elseif ($headerValue === 'ADELANTO 2') {
                $adelanto2Col = $col;
            } elseif ($headerValue === 'ELABORAR 2') {
                $elaborar2Col = $col;
            } elseif ($headerValue === 'TOTAL ELABORADO') {
                $totalElaboradoCol = $col;
            } elseif ($headerValue === 'SOBRANTES') {
                $sobrantesCol = $col;
            } elseif ($headerValue === 'Código de Producto') {
                $productCodeCol = $col;
            }

            $col++;
        }

        dump("ADELANTO INICIAL column: {$adelantoInicialCol}");
        dump("ELABORAR 1 column: {$elaborar1Col}");
        dump("ADELANTO 2 column: {$adelanto2Col}");
        dump("ELABORAR 2 column: {$elaborar2Col}");
        dump("TOTAL ELABORADO column: {$totalElaboradoCol}");
        dump("SOBRANTES column: {$sobrantesCol}");
        dump("Product Code column: {$productCodeCol}");

        // Validate each product row
        $validatedProducts = 0;

        for ($rowIndex = $headerRow + 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
            $productCode = $sheet->getCell($productCodeCol . $rowIndex)->getValue();

            // Skip empty rows
            if (empty($productCode)) {
                continue;
            }

            // Check if we have expected values for this product
            if (!isset($expectedValues[$productCode])) {
                continue;
            }

            dump("Validating row {$rowIndex}: {$productCode}");

            $expected = $expectedValues[$productCode];

            // Validate ADELANTO INICIAL
            if (isset($expected['adelanto_inicial']) && $adelantoInicialCol) {
                $actualAdelantoInicial = $sheet->getCell($adelantoInicialCol . $rowIndex)->getValue();
                dump("  ADELANTO INICIAL: expected={$expected['adelanto_inicial']}, actual={$actualAdelantoInicial}");

                $this->assertEquals(
                    $expected['adelanto_inicial'],
                    $actualAdelantoInicial,
                    "Product {$productCode}: ADELANTO INICIAL should be {$expected['adelanto_inicial']}, but got {$actualAdelantoInicial}"
                );
            }

            // Validate ELABORAR 1
            if (isset($expected['elaborar_1']) && $elaborar1Col) {
                $actualElaborar1 = $sheet->getCell($elaborar1Col . $rowIndex)->getValue();
                dump("  ELABORAR 1: expected={$expected['elaborar_1']}, actual={$actualElaborar1}");

                $this->assertEquals(
                    $expected['elaborar_1'],
                    $actualElaborar1,
                    "Product {$productCode}: ELABORAR 1 should be {$expected['elaborar_1']}, but got {$actualElaborar1}"
                );
            }

            // Validate ADELANTO 2
            if (isset($expected['adelanto_2']) && $adelanto2Col) {
                $actualAdelanto2 = $sheet->getCell($adelanto2Col . $rowIndex)->getValue();
                dump("  ADELANTO 2: expected={$expected['adelanto_2']}, actual={$actualAdelanto2}");

                $this->assertEquals(
                    $expected['adelanto_2'],
                    $actualAdelanto2,
                    "Product {$productCode}: ADELANTO 2 should be {$expected['adelanto_2']}, but got {$actualAdelanto2}"
                );
            }

            // Validate ELABORAR 2
            if (isset($expected['elaborar_2']) && $elaborar2Col) {
                $actualElaborar2 = $sheet->getCell($elaborar2Col . $rowIndex)->getValue();
                dump("  ELABORAR 2: expected={$expected['elaborar_2']}, actual={$actualElaborar2}");

                $this->assertEquals(
                    $expected['elaborar_2'],
                    $actualElaborar2,
                    "Product {$productCode}: ELABORAR 2 should be {$expected['elaborar_2']}, but got {$actualElaborar2}"
                );
            }

            // Validate TOTAL ELABORADO
            if (isset($expected['total_elaborado']) && $totalElaboradoCol) {
                $actualTotalElaborado = $sheet->getCell($totalElaboradoCol . $rowIndex)->getValue();
                dump("  TOTAL ELABORADO: expected={$expected['total_elaborado']}, actual={$actualTotalElaborado}");

                $this->assertEquals(
                    $expected['total_elaborado'],
                    $actualTotalElaborado,
                    "Product {$productCode}: TOTAL ELABORADO should be {$expected['total_elaborado']}, but got {$actualTotalElaborado}"
                );
            }

            // Validate SOBRANTES
            if (isset($expected['sobrantes']) && $sobrantesCol) {
                $actualSobrantes = $sheet->getCell($sobrantesCol . $rowIndex)->getValue();
                dump("  SOBRANTES: expected={$expected['sobrantes']}, actual={$actualSobrantes}");

                $this->assertEquals(
                    $expected['sobrantes'],
                    $actualSobrantes,
                    "Product {$productCode}: SOBRANTES should be {$expected['sobrantes']}, but got {$actualSobrantes}"
                );
            }

            $validatedProducts++;
        }

        // Ensure we validated all expected products
        $this->assertEquals(
            count($expectedValues),
            $validatedProducts,
            "Should have validated " . count($expectedValues) . " products, but only validated {$validatedProducts}"
        );
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
