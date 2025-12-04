<?php

namespace App\Exports;

use App\Models\ExportProcess;
use App\Models\PlatedDish;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Events\BeforeExport;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Plated Dish Ingredients Data Export
 *
 * Exports plated dish ingredients to Excel format compatible with PlatedDishIngredientsImport.
 *
 * VERTICAL FORMAT:
 * - Each row represents ONE ingredient for a product
 * - Same product can have multiple rows (multiple ingredients)
 * - Product with 6 ingredients = 6 rows in Excel
 *
 * Headers and data structure match exactly the import requirements.
 */
class PlatedDishIngredientsDataExport implements
    FromCollection,
    WithHeadings,
    ShouldAutoSize,
    WithStyles,
    WithEvents,
    ShouldQueue
{
    use Exportable;

    /**
     * Headers matching PlatedDishIngredientsImport expected headers
     * IMPORTANT: These MUST match the import headings exactly (7 columns total)
     *
     * From PlatedDishIngredientsImport::getExpectedHeaders():
     * 1. CODIGO DE PRODUCTO
     * 2. NOMBRE DE PRODUCTO
     * 3. EMPLATADO (ingredient code)
     * 4. UNIDAD DE MEDIDA
     * 5. CANTIDAD
     * 6. CANTIDAD MAXIMA (HORECA)
     * 7. VIDA UTIL
     */
    private array $headers = [
        'CODIGO DE PRODUCTO',
        'NOMBRE DE PRODUCTO',
        'EMPLATADO',
        'UNIDAD DE MEDIDA',
        'CANTIDAD',
        'CANTIDAD MAXIMA (HORECA)',
        'VIDA UTIL',
    ];

    private Collection $platedDishIds;
    private int $exportProcessId;

    public function __construct(Collection $platedDishIds, int $exportProcessId)
    {
        $this->platedDishIds = $platedDishIds;
        $this->exportProcessId = $exportProcessId;
    }

    /**
     * Generate collection of rows for export
     *
     * VERTICAL FORMAT: Each ingredient becomes one row
     * Example: Product with 3 ingredients generates 3 rows:
     * Row 1: PROD001 | Product Name | ING001 | GR  | 100 | 150
     * Row 2: PROD001 | Product Name | ING002 | ML  | 50  | 75
     * Row 3: PROD001 | Product Name | ING003 | UND | 2   | 3
     */
    public function collection()
    {
        $rows = collect();

        try {
            // Load plated dishes with relationships
            $platedDishes = PlatedDish::with([
                'product',
                'ingredients',
            ])
                ->whereIn('id', $this->platedDishIds)
                ->get();

            // Generate one row per ingredient
            foreach ($platedDishes as $platedDish) {
                $product = $platedDish->product;

                // Get ingredients ordered by order_index
                $ingredients = $platedDish->ingredients()
                    ->orderBy('order_index')
                    ->get();

                // If no ingredients, skip this plated dish
                if ($ingredients->isEmpty()) {
                    Log::warning('PlatedDish has no ingredients during export', [
                        'export_process_id' => $this->exportProcessId,
                        'plated_dish_id' => $platedDish->id,
                        'product_code' => $product->code,
                    ]);
                    continue;
                }

                // Create one row per ingredient (VERTICAL FORMAT)
                foreach ($ingredients as $ingredient) {
                    $rows->push([
                        'codigo_de_producto' => $product->code,
                        'nombre_de_producto' => $product->name,
                        'emplatado' => $ingredient->ingredient_name,
                        'unidad_de_medida' => $ingredient->measure_unit,
                        'cantidad' => $ingredient->quantity,
                        'cantidad_maxima_horeca' => $ingredient->max_quantity_horeca,
                        'vida_util' => $ingredient->shelf_life,
                    ]);
                }
            }

            Log::info('PlatedDish ingredients export data generated', [
                'export_process_id' => $this->exportProcessId,
                'total_plated_dishes' => $platedDishes->count(),
                'total_rows' => $rows->count(),
            ]);

            return $rows;
        } catch (\Exception $e) {
            Log::error('Error generating plated dish ingredients export data', [
                'export_process_id' => $this->exportProcessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Excel column headers
     *
     * @return array
     */
    public function headings(): array
    {
        return $this->headers;
    }

    /**
     * Apply styles to header row
     *
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2EFDA'], // Light green background
                ],
            ],
        ];
    }

    /**
     * Register export events
     *
     * @return array
     */
    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSING]);

                Log::info('Starting plated dish ingredients export', [
                    'export_process_id' => $this->exportProcessId,
                    'plated_dish_count' => $this->platedDishIds->count(),
                ]);
            },

            AfterSheet::class => function (AfterSheet $event) {
                $exportProcess = ExportProcess::find($this->exportProcessId);

                if ($exportProcess && $exportProcess->status !== ExportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $exportProcess->update(['status' => ExportProcess::STATUS_PROCESSED]);
                }

                Log::info('Finished plated dish ingredients export', [
                    'export_process_id' => $this->exportProcessId,
                ]);
            },
        ];
    }
}