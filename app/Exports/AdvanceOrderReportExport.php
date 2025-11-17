<?php

namespace App\Exports;

use App\Contracts\ReportColumnDataProviderInterface;
use App\Models\ExportProcess;
use App\Repositories\AdvanceOrderRepository;
use App\Models\AdvanceOrder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeExport;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class AdvanceOrderReportExport implements
    FromCollection,
    WithHeadings,
    ShouldAutoSize,
    WithStyles,
    WithEvents,
    ShouldQueue
{
    private array $advanceOrderIds;
    private array $advanceOrders = [];
    private array $columnHeaders = [];
    private ReportColumnDataProviderInterface $columnProvider;
    private bool $showExcludedCompanies = true;
    private bool $showAllAdelantos = true;
    private bool $showTotalElaborado = true;
    private bool $showSobrantes = true;
    private bool $showAdelantoInicial = true;
    private bool $showTotalPedidos = true;
    private array $productionAreaIds = [];
    private int $exportProcessId;

    public function __construct(
        array $advanceOrderIds,
        int|bool $exportProcessIdOrShowExcludedCompanies = 0,
        bool $showExcludedCompaniesOrAllAdelantos = true,
        bool $showAllAdelantosOrTotalElaborado = true,
        bool $showTotalElaboradoOrSobrantes = true,
        bool $showSobrantes = true,
        bool $showAdelantoInicial = true,
        bool $showTotalPedidos = true,
        array $productionAreaIds = []
    )
    {
        // Handle backward compatibility: if second param is bool, it's the old 5-param signature
        if (is_bool($exportProcessIdOrShowExcludedCompanies)) {
            $this->advanceOrderIds = $advanceOrderIds;
            $this->exportProcessId = 0;
            $this->showExcludedCompanies = $exportProcessIdOrShowExcludedCompanies;
            $this->showAllAdelantos = $showExcludedCompaniesOrAllAdelantos;
            $this->showTotalElaborado = $showAllAdelantosOrTotalElaborado;
            $this->showSobrantes = $showTotalElaboradoOrSobrantes;
            $this->showAdelantoInicial = true;
            $this->showTotalPedidos = true;
            $this->productionAreaIds = [];
        } else {
            $this->advanceOrderIds = $advanceOrderIds;
            $this->exportProcessId = $exportProcessIdOrShowExcludedCompanies;
            $this->showExcludedCompanies = $showExcludedCompaniesOrAllAdelantos;
            $this->showAllAdelantos = $showAllAdelantosOrTotalElaborado;
            $this->showTotalElaborado = $showTotalElaboradoOrSobrantes;
            $this->showSobrantes = $showSobrantes;
            $this->showAdelantoInicial = $showAdelantoInicial;
            $this->showTotalPedidos = $showTotalPedidos;
            $this->productionAreaIds = $productionAreaIds;
        }

        // Inject column provider service from container
        $this->columnProvider = app(ReportColumnDataProviderInterface::class);

        // Load column headers from service (companies, groupers, etc.)
        $this->columnHeaders = $this->columnProvider->getColumnHeaders($advanceOrderIds);

        // Load advance orders ordered by created_at
        $this->advanceOrders = AdvanceOrder::whereIn('id', $advanceOrderIds)
            ->orderBy('created_at', 'asc')
            ->get()
            ->keyBy('id')
            ->toArray();
    }
    
    /**
     * Return the collection of data to export
     */
    public function collection()
    {
        $repository = app(AdvanceOrderRepository::class);

        // Use the grouped by production area method (works for single or multiple OPs)
        $productionAreas = $repository->getAdvanceOrderProductsGroupedByProductionArea($this->advanceOrderIds);

        // Filter by production area IDs if provided
        if (!empty($this->productionAreaIds)) {
            $productionAreas = $productionAreas->filter(function($area) {
                return in_array($area['production_area_id'], $this->productionAreaIds);
            });
        }

        $rows = collect();

        // Calculate date range from all OPs
        $allDates = collect();
        foreach ($this->advanceOrders as $opId => $op) {
            $allDates->push($op['initial_dispatch_date']);
            $allDates->push($op['final_dispatch_date']);
        }
        $minDate = $allDates->filter()->min();
        $maxDate = $allDates->filter()->max();

        // Add date range information at the top (as indexed array)
        // Order: Category, Code, Name, Total, Companies, OPs, Total Elaborado, Sobrantes
        $dateHeaderRow = ['RANGO DE FECHAS DE DESPACHO:', '', '', ''];
        // Add empty values for excluded companies
        foreach ($this->columnHeaders as $columnKey => $displayName) {
            $dateHeaderRow[] = '';
        }
        // Add empty values for OPs columns
        foreach ($this->advanceOrders as $opId => $op) {
            $dateHeaderRow[] = '';
            $dateHeaderRow[] = '';
        }
        $dateHeaderRow[] = ''; // total_elaborado
        $dateHeaderRow[] = ''; // sobrantes
        $rows->push($dateHeaderRow);

        $dateValueRow = ['Desde: ' . \Carbon\Carbon::parse($minDate)->format('d/m/Y') . ' - Hasta: ' . \Carbon\Carbon::parse($maxDate)->format('d/m/Y'), '', '', ''];
        // Add empty values for excluded companies
        foreach ($this->columnHeaders as $columnKey => $displayName) {
            $dateValueRow[] = '';
        }
        // Add empty values for OPs columns
        foreach ($this->advanceOrders as $opId => $op) {
            $dateValueRow[] = '';
            $dateValueRow[] = '';
        }
        $dateValueRow[] = ''; // total_elaborado
        $dateValueRow[] = ''; // sobrantes
        $rows->push($dateValueRow);

        // Add empty row separator
        $emptyRow = ['', '', '', ''];
        // Add empty values for excluded companies
        foreach ($this->columnHeaders as $columnKey => $displayName) {
            $emptyRow[] = '';
        }
        // Add empty values for OPs columns
        foreach ($this->advanceOrders as $opId => $op) {
            $emptyRow[] = '';
            $emptyRow[] = '';
        }
        $emptyRow[] = ''; // total_elaborado
        $emptyRow[] = ''; // sobrantes
        $rows->push($emptyRow);

        foreach ($productionAreas as $area) {
            // Add production area header row (spanning all columns) as indexed array
            // Order: Category, Code, Name, Total, Companies, OPs, Total Elaborado, Sobrantes
            $headerRow = [strtoupper($area['production_area_name']), '', '', ''];

            // Add empty columns for excluded companies
            foreach ($this->columnHeaders as $columnKey => $displayName) {
                $headerRow[] = '';
            }

            // Add empty columns for OPs
            foreach ($this->advanceOrders as $opId => $op) {
                $headerRow[] = '';
                $headerRow[] = '';
            }
            $headerRow[] = ''; // total_elaborado
            $headerRow[] = ''; // sobrantes

            $rows->push($headerRow);

            // Add products for this production area
            foreach ($area['products'] as $product) {
                $row = [
                    'category_name' => $product['category_name'],
                    'product_code' => $product['product_code'],
                    'product_name' => $product['product_name'],
                    'total_ordered_quantity' => $product['total_ordered_quantity'] == 0 ? ' 0' : $product['total_ordered_quantity'],
                ];

                // Add columns for excluded companies IMMEDIATELY AFTER total_ordered_quantity
                foreach ($this->columnHeaders as $columnKey => $displayName) {
                    if (isset($product['columns'][$columnKey])) {
                        $companyQty = $product['columns'][$columnKey]['total_quantity'];
                        $row[$columnKey] = $companyQty == 0 ? ' 0' : $companyQty;
                    } else {
                        $row[$columnKey] = ' 0';
                    }
                }

                // Track total_to_produce sum for "Total Elaborado" column
                $totalElaborado = 0;

                // Add columns for each OP dynamically
                $opOrder = 1;
                foreach ($this->advanceOrders as $opId => $op) {
                    if (isset($product['ops'][$opId])) {
                        $opData = $product['ops'][$opId];
                        // Force zero to be displayed by converting to string with prefix
                        $adelanto = $opData['manual_quantity'];
                        $elaborar = $opData['total_to_produce'];

                        // Add to total elaborado
                        $totalElaborado += ($elaborar === 0 || $elaborar === '0' || $elaborar === null) ? 0 : $elaborar;

                        $row['adelanto_' . $opOrder] = $adelanto === 0 || $adelanto === '0' || $adelanto === null ? ' 0' : $adelanto;
                        $row['elaborar_' . $opOrder] = $elaborar === 0 || $elaborar === '0' || $elaborar === null ? ' 0' : $elaborar;
                    } else {
                        // Product not in this OP - also show as 0 per user request
                        $row['adelanto_' . $opOrder] = ' 0';
                        $row['elaborar_' . $opOrder] = ' 0';
                    }
                    $opOrder++;
                }

                // Add "Total Elaborado" column
                $row['total_elaborado'] = $totalElaborado == 0 ? ' 0' : $totalElaborado;

                // Add "Sobrantes" column (Current stock from warehouse)
                $currentStock = $product['current_stock'] ?? 0;
                $row['sobrantes'] = $currentStock == 0 ? ' 0' : $currentStock;

                // Log first product row for debugging
                static $firstProductLogged = false;
                if (!$firstProductLogged) {
                    $firstProductLogged = true;
                }

                // Convert associative array to indexed array to preserve column order
                $indexedRow = array_values($row);
                
                $rows->push($indexedRow);
            }

            // Add TOTAL row for this production area (using data from repository)
            if (isset($area['total_row'])) {
                $totalRow = [
                    'category_name' => $area['total_row']['category_name'],
                    'product_code' => $area['total_row']['product_code'],
                    'product_name' => $area['total_row']['product_name'],
                    'total_ordered_quantity' => $area['total_row']['total_ordered_quantity'] == 0 ? ' 0' : $area['total_row']['total_ordered_quantity'],
                ];

                // Add company totals
                foreach ($this->columnHeaders as $columnKey => $displayName) {
                    if (isset($area['total_row']['columns'][$columnKey])) {
                        $companyQty = $area['total_row']['columns'][$columnKey]['total_quantity'];
                        $totalRow[$columnKey] = $companyQty == 0 ? ' 0' : $companyQty;
                    } else {
                        $totalRow[$columnKey] = ' 0';
                    }
                }

                // Add OP totals
                $opOrder = 1;
                $totalElaborado = 0;
                foreach ($this->advanceOrders as $opId => $op) {
                    if (isset($area['total_row']['ops'][$opId])) {
                        $opData = $area['total_row']['ops'][$opId];
                        $adelanto = $opData['manual_quantity'];
                        $elaborar = $opData['total_to_produce'];

                        $totalElaborado += ($elaborar === 0 || $elaborar === '0' || $elaborar === null) ? 0 : $elaborar;

                        $totalRow['adelanto_' . $opOrder] = $adelanto === 0 || $adelanto === '0' || $adelanto === null ? ' 0' : $adelanto;
                        $totalRow['elaborar_' . $opOrder] = $elaborar === 0 || $elaborar === '0' || $elaborar === null ? ' 0' : $elaborar;
                    } else {
                        $totalRow['adelanto_' . $opOrder] = ' 0';
                        $totalRow['elaborar_' . $opOrder] = ' 0';
                    }
                    $opOrder++;
                }

                // Add total elaborado and sobrantes (empty for TOTAL row)
                $totalRow['total_elaborado'] = $totalElaborado == 0 ? ' 0' : $totalElaborado;
                $totalRow['sobrantes'] = '';

                // Convert to indexed array
                $indexedTotalRow = array_values($totalRow);
                $rows->push($indexedTotalRow);
            }
        }

        return $rows;
    }

    /**
     * Define the headings for the Excel file
     */
    public function headings(): array
    {
        
        // Use the same dynamic header structure for both single and multiple OPs
        // This ensures consistency and supports excluded companies + total rows
        $headers = [
            'Categoría',
            'Código de Producto',
            'Nombre del Producto',
            'TOTAL PEDIDOS',
        ];

        // Add headers for excluded companies AFTER "TOTAL PEDIDOS"
        foreach ($this->columnHeaders as $companyKey => $companyName) {
            $headers[] = strtoupper($companyName);
        }

        // Add headers for each OP
        $opOrder = 1;
        foreach ($this->advanceOrders as $opId => $op) {
            if ($opOrder === 1) {
                $headers[] = 'ADELANTO INICIAL';
                $headers[] = 'ELABORAR 1';
            } else {
                $headers[] = 'ADELANTO ' . $opOrder;
                $headers[] = 'ELABORAR ' . $opOrder;
            }
            $opOrder++;
        }

        // Add final summary columns
        $headers[] = 'TOTAL ELABORADO';
        $headers[] = 'SOBRANTES';
        
        return $headers;
    }

    /**
     * Apply styles to the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        // Get highest column (last column with data)
        $highestColumn = $sheet->getHighestColumn();
        $highestRow = $sheet->getHighestRow();

        // Style for all headers (row 1) - base style with green background
        $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2EFDA']
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
        ]);

        // Apply vertical text orientation to columns after "Nombre del Producto" (column D onwards)
        $sheet->getStyle('D1:' . $highestColumn . '1')->applyFromArray([
            'alignment' => [
                'textRotation' => 90,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_BOTTOM,
            ],
        ]);

        // Style date range rows (rows 2 and 3)
        // Row 2: "RANGO DE FECHAS DE DESPACHO:" header
        $sheet->getStyle('A2:' . $highestColumn . '2')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => '000000']
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9E1F2']
            ],
        ]);
        $sheet->mergeCells('A2:' . $highestColumn . '2');

        // Row 3: Date range values
        $sheet->getStyle('A3:' . $highestColumn . '3')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 11,
            ],
        ]);
        $sheet->mergeCells('A3:' . $highestColumn . '3');

        // Find and style production area header rows (rows where column B is empty but column A has text, starting from row 5)
        for ($row = 5; $row <= $highestRow; $row++) {
            $cellA = $sheet->getCell('A' . $row)->getValue();
            $cellB = $sheet->getCell('B' . $row)->getValue();

            // If column A has text but column B is empty, it's a production area header
            if (!empty($cellA) && empty($cellB)) {
                // Apply bold, larger font, black background, white text
                $sheet->getStyle('A' . $row . ':' . $highestColumn . $row)->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 14,
                        'color' => ['rgb' => 'FFFFFF']
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '000000']
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                    ],
                ]);

                // Merge cells for production area name
                $sheet->mergeCells('A' . $row . ':' . $highestColumn . $row);
            }
        }

        // Find and style TOTAL rows (rows where column C contains "TOTAL")
        for ($row = 5; $row <= $highestRow; $row++) {
            $cellC = $sheet->getCell('C' . $row)->getValue();

            // If column C (Nombre del Producto) contains "TOTAL", apply bold
            if ($cellC === 'TOTAL') {
                $sheet->getStyle('A' . $row . ':' . $highestColumn . $row)->applyFromArray([
                    'font' => [
                        'bold' => true,
                    ],
                ]);
            }
        }

        // Apply font size 18 to ALL headers (row 1, all columns)
        $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
            'font' => [
                'size' => 18,
            ],
        ]);

        // Apply font size 18 to date range header (row 2)
        $sheet->getStyle('A2:' . $highestColumn . '2')->applyFromArray([
            'font' => [
                'size' => 18,
            ],
        ]);

        // Apply font size 18 to date range values (row 3)
        $sheet->getStyle('A3:' . $highestColumn . '3')->applyFromArray([
            'font' => [
                'size' => 18,
            ],
        ]);

        // Apply font size 18 to ALL data rows (columns A to last column, from row 5 onwards)
        // This includes product names (A, B, C) and all numeric values (D onwards)
        $sheet->getStyle('A5:' . $highestColumn . $highestRow)->applyFromArray([
            'font' => [
                'size' => 18,
            ],
        ]);

        // Align all numeric columns (D onwards) to the left for data rows (row 2 onwards)
        $sheet->getStyle('D2:' . $highestColumn . $highestRow)->applyFromArray([
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
            ],
        ]);

        // Apply alternating row colors to product rows (before column colors)
        $this->applyAlternatingRowColors($sheet, $highestRow);

        // Apply colors to dynamic columns
        // Column order: A-C (Category, Code, Name), D (TOTAL PEDIDOS), E-G (Companies), H onwards (OPs), last 2 (Total Elaborado, Sobrantes)
        $columnIndex = 5; // Start at E (5th column) - after TOTAL PEDIDOS
        
        // Apply light yellow color to excluded company columns FIRST (columns E, F, G, etc.)
        if (!empty($this->columnHeaders)) {
            $companyCount = count($this->columnHeaders);

            for ($i = 0; $i < $companyCount; $i++) {
                $companyColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
                $sheet->getStyle($companyColumn . '1:' . $companyColumn . $highestRow)->applyFromArray([
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'FFF2CC'] // Yellow light for excluded companies
                    ],
                ]);
                $columnIndex++;
            }
        }

        // Now apply colors for each OP's Adelanto and Elaborar columns
        $opCount = count($this->advanceOrders);
        for ($i = 0; $i < $opCount; $i++) {
            // Adelanto column - Pink/Salmon color for all rows
            $adelantoColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getStyle($adelantoColumn . '1:' . $adelantoColumn . $highestRow)->applyFromArray([
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFC7CE']
                ],
            ]);
            $columnIndex++;

            // Elaborar column - Yellow color for all rows
            $elaborarColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getStyle($elaborarColumn . '1:' . $elaborarColumn . $highestRow)->applyFromArray([
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFEB9C']
                ],
            ]);
            $columnIndex++;
        }

        // Apply yellow to "TOTAL ELABORADO" and "SOBRANTES" columns for all rows
        $totalElaboradoColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
        $sheet->getStyle($totalElaboradoColumn . '1:' . $totalElaboradoColumn . $highestRow)->applyFromArray([
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFEB9C']
            ],
        ]);
        $columnIndex++;

        $sobrantesColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
        $sheet->getStyle($sobrantesColumn . '1:' . $sobrantesColumn . $highestRow)->applyFromArray([
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFEB9C']
            ],
        ]);

        // Hide columns based on parameters
        $currentColumnIndex = 5; // Start at E (after TOTAL PEDIDOS - column D is index 4)

        // 0. Hide TOTAL PEDIDOS column if showTotalPedidos is false
        if (!$this->showTotalPedidos) {
            $totalPedidosColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(4);
            $sheet->getColumnDimension($totalPedidosColumn)->setVisible(false);
        }

        // 1. Hide excluded companies columns if showExcludedCompanies is false
        if (!$this->showExcludedCompanies && !empty($this->columnHeaders)) {
            $companyCount = count($this->columnHeaders);

            for ($i = 0; $i < $companyCount; $i++) {
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentColumnIndex + $i);
                $sheet->getColumnDimension($columnLetter)->setVisible(false);
            }
        }

        // Move index past company columns
        $currentColumnIndex += count($this->columnHeaders);

        // 2. Hide ADELANTO INICIAL column if showAdelantoInicial is false
        if (!$this->showAdelantoInicial) {
            $adelantoInicialColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentColumnIndex);
            $sheet->getColumnDimension($adelantoInicialColumn)->setVisible(false);
        }
        $currentColumnIndex++;

        // Move past first elaborar column
        $currentColumnIndex++;

        // 3. Hide non-initial adelanto columns if showAllAdelantos is false
        if (!$this->showAllAdelantos && count($this->advanceOrders) > 1) {
            $opCount = count($this->advanceOrders);

            // Hide adelanto columns from OP 2 onwards (only adelanto, not elaborar)
            for ($i = 1; $i < $opCount; $i++) {
                // Hide adelanto column (every odd column in OP pairs)
                $adelantoColumnIndex = $currentColumnIndex + (($i - 1) * 2);
                $adelantoColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($adelantoColumnIndex);
                $sheet->getColumnDimension($adelantoColumn)->setVisible(false);

            }

            // Move index past all remaining OP columns (OP 2 onwards)
            $currentColumnIndex += (($opCount - 1) * 2);
        } else {
            // Move index past remaining OP columns (OP 2 onwards: adelanto + elaborar for each)
            $currentColumnIndex += ((count($this->advanceOrders) - 1) * 2);
        }

        // 4. Hide TOTAL ELABORADO column if showTotalElaborado is false
        if (!$this->showTotalElaborado) {
            $totalElaboradoColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentColumnIndex);
            $sheet->getColumnDimension($totalElaboradoColumn)->setVisible(false);
        }
        $currentColumnIndex++;

        // 5. Hide SOBRANTES column if showSobrantes is false
        if (!$this->showSobrantes) {
            $sobrantesColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($currentColumnIndex);
            $sheet->getColumnDimension($sobrantesColumn)->setVisible(false);
        }

        return [];
    }

    /**
     * Apply alternating row colors to product rows
     *
     * @param Worksheet $sheet The worksheet to apply colors to
     * @param int $highestRow The last row in the sheet
     * @return void
     */
    private function applyAlternatingRowColors(Worksheet $sheet, int $highestRow): void
    {
        $alternateColor = false;

        for ($row = 5; $row <= $highestRow; $row++) {
            $cellA = $sheet->getCell('A' . $row)->getValue();
            $cellB = $sheet->getCell('B' . $row)->getValue();
            $cellC = $sheet->getCell('C' . $row)->getValue();

            // Skip production area headers (A has text, B and C empty)
            if (!empty($cellA) && empty($cellB) && empty($cellC)) {
                continue;
            }

            // Skip TOTAL rows (C contains "TOTAL")
            if ($cellC === 'TOTAL') {
                continue;
            }

            // Apply alternating color to product rows (columns A to D only)
            // Columns E onwards will keep their predefined colors (yellow, pink, etc.)
            if ($alternateColor) {
                $sheet->getStyle('A' . $row . ':D' . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F2F2F2'] // Light gray
                    ],
                ]);
            }

            // Toggle color for next product row
            $alternateColor = !$alternateColor;
        }
    }

    /**
     * Register events for export process status updates
     */
    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSING]);

                Log::info('Advance order report export started', [
                    'export_process_id' => $this->exportProcessId,
                    'advance_order_ids' => $this->advanceOrderIds
                ]);
            },
            AfterSheet::class => function (AfterSheet $event) {
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSED]);

                Log::info('Advance order report export completed', [
                    'export_process_id' => $this->exportProcessId,
                    'advance_order_ids' => $this->advanceOrderIds
                ]);
            },
        ];
    }

    /**
     * Handle a failed export
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $e): void
    {
        $currentUser = exec('whoami');

        $error = [
            'row' => 0,
            'attribute' => 'export',
            'errors' => [$e->getMessage()],
            'values' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user' => $currentUser
            ],
        ];

        // Get current export process and existing errors
        $exportProcess = ExportProcess::find($this->exportProcessId);
        $existingErrors = $exportProcess->error_log ?? [];

        // Add new error to existing array
        $existingErrors[] = $error;

        // Update error_log in ExportProcess
        $exportProcess->update([
            'error_log' => $existingErrors,
            'status' => ExportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::error('Error in Advance Order Report export', [
            'export_process_id' => $this->exportProcessId,
            'advance_order_ids' => $this->advanceOrderIds,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
