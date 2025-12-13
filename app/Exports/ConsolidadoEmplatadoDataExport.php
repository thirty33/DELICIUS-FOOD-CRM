<?php

namespace App\Exports;

use App\Models\ExportProcess;
use App\Repositories\ConsolidadoEmplatadoRepository;
use App\Support\ImportExport\ConsolidadoEmplatadoSchema;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Events\BeforeExport;
use Maatwebsite\Excel\Concerns\WithEvents;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Consolidado Emplatado Data Export
 *
 * Exports consolidated plated dish report data to Excel format.
 * Uses ConsolidadoEmplatadoSchema for dynamic column generation.
 *
 * NOTE: We do NOT use WithHeadings because we need to insert title and date rows
 * BEFORE the headers. Instead, we include headers within collection().
 */
class ConsolidadoEmplatadoDataExport implements
    FromCollection,
    ShouldAutoSize,
    WithStyles,
    WithEvents,
    ShouldQueue
{
    use Exportable;

    private array $advanceOrderIds;
    private int $exportProcessId;
    private array $branchNames;
    private ConsolidadoEmplatadoRepository $repository;
    private array $flatData;
    private array $productRowGroups = [];
    private string $minDate;
    private string $maxDate;

    public function __construct(Collection $advanceOrderIds, array $branchNames, int $exportProcessId)
    {
        
        $this->advanceOrderIds = $advanceOrderIds->toArray();
        $this->branchNames = $branchNames;
        $this->exportProcessId = $exportProcessId;
        $this->repository = app(ConsolidadoEmplatadoRepository::class);

        // Get date range from advance orders
        $advanceOrders = \App\Models\AdvanceOrder::whereIn('id', $this->advanceOrderIds)->get();
        $allDates = collect();
        foreach ($advanceOrders as $op) {
            $allDates->push($op->initial_dispatch_date);
            $allDates->push($op->final_dispatch_date);
        }
        $this->minDate = \Carbon\Carbon::parse($allDates->filter()->min())->format('d/m/Y');
        $this->maxDate = \Carbon\Carbon::parse($allDates->filter()->max())->format('d/m/Y');

        // Configure schema with branch names BEFORE getting data
        // This is critical for queue serialization - branch names are stored as property
        ConsolidadoEmplatadoSchema::setClientColumns($this->branchNames);

        // Get flat format data
        $this->flatData = $this->repository->getConsolidatedPlatedDishData($this->advanceOrderIds, true);
    }

    /**
     * Return collection of flat data ready for Excel export
     */
    public function collection(): Collection
    {
        
        // Get total column count from schema
        $columnCount = count(ConsolidadoEmplatadoSchema::getHeaders());

        // Initialize collection with title and date rows
        $rows = collect();

        // Row 1: Title "CONSOLIDADO DE INGREDIENTES - EMPLATADO" (merged across all columns)
        $titleRow = ['CONSOLIDADO DE INGREDIENTES - EMPLATADO'];
        for ($i = 1; $i < $columnCount; $i++) {
            $titleRow[] = '';
        }
        $rows->push($titleRow);

        // Row 2: Date range "FECHA: Desde: DD/MM/YYYY - Hasta: DD/MM/YYYY"
        $dateRow = ['FECHA:      Desde: ' . $this->minDate . ' - Hasta: ' . $this->maxDate];
        for ($i = 1; $i < $columnCount; $i++) {
            $dateRow[] = '';
        }
        $rows->push($dateRow);

        // Row 3: Headers (from schema, uppercase)
        $headers = ConsolidadoEmplatadoSchema::getHeaders();
        $headerValues = array_map('strtoupper', array_values($headers));
        $rows->push($headerValues);

        // Track row groups for merging PLATO column
        // Start at row 4 (row 1 = title, row 2 = date, row 3 = headers, row 4 = first data row)
        $currentRow = 4;
        $currentProduct = null;
        $productStartRow = null;
        $totalRows = count($this->flatData);
        $rowIndex = 0;

        // Use cached flat data and add to collection
        $dataRows = collect($this->flatData)->map(function ($row) use (&$currentRow, &$currentProduct, &$productStartRow, &$rowIndex, $totalRows) {
            $rowIndex++;
            $platoValue = $row[array_keys($row)[0]]; // First column is PLATO
            $isLastRow = ($rowIndex === $totalRows); // Detect totals row (last row)

            // If PLATO has value, it's a new product group (and NOT the totals row)
            if (!empty($platoValue) && !$isLastRow) {
                // Save previous product group if exists
                if ($currentProduct !== null && $productStartRow !== null) {
                    $this->productRowGroups[] = [
                        'start' => $productStartRow,
                        'end' => $currentRow - 1,
                        'product' => $currentProduct,
                    ];
                }

                // Start new product group
                $currentProduct = $platoValue;
                $productStartRow = $currentRow;
            }

            // If this is the last row (totals row), close the previous product group
            if ($isLastRow && $currentProduct !== null && $productStartRow !== null) {
                $this->productRowGroups[] = [
                    'start' => $productStartRow,
                    'end' => $currentRow - 1, // End BEFORE the totals row
                    'product' => $currentProduct,
                ];
                // Reset to prevent saving again after loop
                $currentProduct = null;
                $productStartRow = null;
            }

            $currentRow++;

            // Convert all values to uppercase and return
            return array_map(function ($value) {
                return is_string($value) ? strtoupper($value) : $value;
            }, array_values($row));
        });

        // Save last product group (only if not already saved by totals row detection)
        if ($currentProduct !== null && $productStartRow !== null) {
            $this->productRowGroups[] = [
                'start' => $productStartRow,
                'end' => $currentRow - 1,
                'product' => $currentProduct,
            ];
        }

        // Merge title/date/headers rows with data rows
        return $rows->merge($dataRows);
    }

    /**
     * Apply styles to worksheet
     */
    public function styles(Worksheet $sheet)
    {
        // Get the highest row and column
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        // Apply Arial 10 to entire sheet
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial');
        $sheet->getParent()->getDefaultStyle()->getFont()->setSize(10);

        // Apply wrap text to all data cells (skip title, date, and header rows)
        $dataRange = "A4:{$highestColumn}{$highestRow}";
        $sheet->getStyle($dataRange)->getAlignment()->setWrapText(true);
        $sheet->getStyle($dataRange)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        // Row 1: Title styling (bold, centered, dark blue background)
        // Row 2: Date styling (regular font)
        // Row 3: Header row styling (dark blue like in the image)
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'], // White text
                    'name' => 'Arial',
                    'size' => 12,
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2F5496'], // Dark blue
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
            2 => [
                'font' => [
                    'bold' => false,
                    'name' => 'Arial',
                    'size' => 10,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
            3 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'], // White text
                    'name' => 'Arial',
                    'size' => 10,
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2F5496'], // Dark blue like in image
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    /**
     * Register events for cell merging and export status tracking
     */
    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSING]);
            },
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();

                // Re-configure schema with branch names (critical for AfterSheet event)
                ConsolidadoEmplatadoSchema::setClientColumns($this->branchNames);

                // Get column count to find TOTAL HORECA and TOTAL BOLSAS positions
                // Note: stringFromColumnIndex uses 1-based index, so column 14 = index 14
                $headers = ConsolidadoEmplatadoSchema::getHeaders();
                $columnCount = count($headers);
                // TOTAL HORECA is second-to-last column (columnCount - 1), TOTAL BOLSAS is last (columnCount)
                $totalHorecaColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnCount - 1); // e.g., column 13 = M
                $totalBolsasColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnCount); // e.g., column 14 = N
                
                // Step 0: Merge title row (row 1) across all columns
                $sheet->mergeCells("A1:{$highestColumn}1");

                // Step 1: Apply alternating row colors to data rows ONLY (starting from row 4)
                // This provides contrast between rows
                for ($row = 4; $row <= $highestRow; $row++) {
                    if (($row - 4) % 2 === 1) { // Odd data rows get light gray
                        $sheet->getStyle("A{$row}:{$highestColumn}{$row}")
                            ->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()
                            ->setRGB('F2F2F2'); // Very light gray for contrast
                    }
                    // Even rows remain white (default)
                }

                // Step 2: Apply salmon pink color to PLATO column data rows (A4:A{highestRow})
                $sheet->getStyle("A4:A{$highestRow}")
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('F4CCCC'); // Salmon pink for entire PLATO column

                // Step 3: Apply strong borders to entire table (from row 3 = headers)
                $sheet->getStyle("A3:{$highestColumn}{$highestRow}")
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                    ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('000000')); // Black borders

                // Step 4: Merge cells in PLATO, INDIVIDUAL, and TOTAL HORECA columns for each product group
                // These columns show product-level values that should span all ingredient rows
                foreach ($this->productRowGroups as $group) {
                    $startRow = $group['start'];
                    $endRow = $group['end'];

                    if ($endRow > $startRow) {
                        // Merge PLATO column (A)
                        $sheet->mergeCells("A{$startRow}:A{$endRow}");
                        $sheet->getStyle("A{$startRow}:A{$endRow}")
                            ->getAlignment()
                            ->setVertical(Alignment::VERTICAL_CENTER);

                        // Merge INDIVIDUAL column (D)
                        $sheet->mergeCells("D{$startRow}:D{$endRow}");
                        $sheet->getStyle("D{$startRow}:D{$endRow}")
                            ->getAlignment()
                            ->setVertical(Alignment::VERTICAL_CENTER);

                        // Merge TOTAL HORECA column (second-to-last)
                        $sheet->mergeCells("{$totalHorecaColumn}{$startRow}:{$totalHorecaColumn}{$endRow}");
                        $sheet->getStyle("{$totalHorecaColumn}{$startRow}:{$totalHorecaColumn}{$endRow}")
                            ->getAlignment()
                            ->setVertical(Alignment::VERTICAL_CENTER);
                    }
                }

                // Apply bold to specific columns: PLATO (A), INGREDIENTE (B), INDIVIDUAL (D), TOTAL HORECA, TOTAL BOLSAS
                // Column A: PLATO - Bold + Center alignment (data rows only, starting from row 4)
                $sheet->getStyle("A4:A{$highestRow}")->getFont()->setBold(true);
                $sheet->getStyle("A4:A{$highestRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Column B: INGREDIENTE
                $sheet->getStyle("B4:B{$highestRow}")->getFont()->setBold(true);

                // Column D: INDIVIDUAL - Bold + Blue background
                $sheet->getStyle("D4:D{$highestRow}")
                    ->getFont()
                    ->setBold(true);
                $sheet->getStyle("D4:D{$highestRow}")
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('B4C7E7'); // Light blue
                $sheet->getStyle("D4:D{$highestRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // TOTAL HORECA column (second to last) - Bold + Blue background
                $sheet->getStyle("{$totalHorecaColumn}4:{$totalHorecaColumn}{$highestRow}")
                    ->getFont()
                    ->setBold(true);
                $sheet->getStyle("{$totalHorecaColumn}4:{$totalHorecaColumn}{$highestRow}")
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('B4C7E7'); // Light blue
                $sheet->getStyle("{$totalHorecaColumn}4:{$totalHorecaColumn}{$highestRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // TOTAL BOLSAS column (last) - Bold + Blue background
                $sheet->getStyle("{$totalBolsasColumn}4:{$totalBolsasColumn}{$highestRow}")
                    ->getFont()
                    ->setBold(true);
                $sheet->getStyle("{$totalBolsasColumn}4:{$totalBolsasColumn}{$highestRow}")
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('B4C7E7'); // Light blue

                // Step 5: Apply special styling to totals row (last row)
                // Yellow background for INDIVIDUAL column in totals row
                $sheet->getStyle("D{$highestRow}")
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('FFD966'); // Light yellow

                // Yellow background for TOTAL HORECA column in totals row
                $sheet->getStyle("{$totalHorecaColumn}{$highestRow}")
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('FFD966'); // Light yellow

                // Yellow background for TOTAL BOLSAS column in totals row
                $sheet->getStyle("{$totalBolsasColumn}{$highestRow}")
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('FFD966'); // Light yellow

                // Update export process status
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSED]);
            },
        ];
    }
}