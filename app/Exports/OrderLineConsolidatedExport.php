<?php

namespace App\Exports;

use App\Models\OrderLine;
use App\Models\Order;
use App\Models\ExportProcess;
use App\Models\Product;
use App\Models\PriceListLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeExport;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Throwable;

class OrderLineConsolidatedExport implements
    FromQuery,
    WithMapping,
    WithHeadings,
    ShouldAutoSize,
    WithStyles,
    WithEvents,
    ShouldQueue,
    WithChunkReading
{
    use Exportable;

    private $headers = [
        'categoria' => 'Categoría',
        'codigo_producto' => 'Código de Producto',
        'nombre_producto' => 'Nombre del Producto',
        'descripcion_producto' => 'Descripción del Producto',
        'cafe_consolidado' => 'CAFÉ CONSOLIDADO',
        'cafe_individual' => 'CAFÉ INDIVIDUAL',
        'convenio_consolidado' => 'CONVENIO CONSOLIDADO',
        'convenio_individual' => 'CONVENIO INDIVIDUAL',
        'suma_total' => 'Suma Total',
        'total_categoria' => 'Total Categoría'
    ];

    private $exportProcessId;
    private $orderLineIds;
    private $productsData = null;
    private $categoryTotals = [];
    private $lastCategoryId = null;
    private $categoryRowSpans = [];
    private $excludedCompanies = [];
    private $companyHeaders = [];
    private $categoryProductCount = [];
    private $excludedCompaniesLoaded = false;
    private $productsProcessed = [];

    public function __construct(Collection $orderLineIds, int $exportProcessId)
    {
        $this->orderLineIds = $orderLineIds;
        $this->exportProcessId = $exportProcessId;
    }

    /**
     * Tamaño de los chunks para procesar
     */
    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * Carga las empresas excluidas para usarlas como columnas
     */
    private function loadExcludedCompanies()
    {
        if ($this->excludedCompaniesLoaded) {
            return;
        }

        // Obtener empresas que tienen exclude_from_consolidated_report = true
        // y que tienen pedidos relacionados con las OrderLines proporcionadas
        $excludedCompaniesData = DB::table('companies')
            ->select('companies.id', 'companies.name', 'companies.registration_number')
            ->join('users', 'companies.id', '=', 'users.company_id')
            ->join('orders', 'users.id', '=', 'orders.user_id')
            ->join('order_lines', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('order_lines.id', $this->orderLineIds)
            ->where('companies.exclude_from_consolidated_report', true)
            ->distinct()
            ->get();

        foreach ($excludedCompaniesData as $company) {
            $columnKey = 'company_' . $company->id;

            // Usar registration_number como identificador
            $displayName = !empty($company->registration_number) ? 'REG-' . $company->registration_number : 'EC' . $company->id;

            $this->excludedCompanies[$company->id] = [
                'id' => $company->id,
                'name' => $company->name,
                'registration_number' => $company->registration_number,
                'column_key' => $columnKey,
                'display_name' => $displayName
            ];

            // Agregar al array de headers
            $this->companyHeaders[$columnKey] = $displayName;
        }

        // Agregar las columnas de empresas excluidas al array de headers
        if (!empty($this->companyHeaders)) {
            // Insertar antes de 'suma_total'
            $newHeaders = [];
            foreach ($this->headers as $key => $value) {
                if ($key === 'suma_total') {
                    foreach ($this->companyHeaders as $compKey => $compValue) {
                        $newHeaders[$compKey] = $compValue;
                    }
                }
                $newHeaders[$key] = $value;
            }
            $this->headers = $newHeaders;
        }

        $this->excludedCompaniesLoaded = true;
    }

    /**
     * Prepara los datos agregados de productos para usar en el mapeo
     */
    private function prepareProductData()
    {
        if ($this->productsData !== null) {
            return $this->productsData;
        }

        // Cargar empresas excluidas primero
        $this->loadExcludedCompanies();

        try {
            $this->productsData = [];
            $this->categoryTotals = [];
            $this->categoryProductCount = [];

            Log::info("Preparando datos para exportación", [
                'export_process_id' => $this->exportProcessId,
                'order_line_ids_count' => count($this->orderLineIds)
            ]);

            // Obtener datos de líneas de pedido
            $orderLines = DB::table('order_lines')
                ->join('products', 'order_lines.product_id', '=', 'products.id')
                ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
                ->join('orders', 'order_lines.order_id', '=', 'orders.id')
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->join('companies', 'users.company_id', '=', 'companies.id')
                ->leftJoin('role_user', 'users.id', '=', 'role_user.user_id')
                ->leftJoin('roles', 'role_user.role_id', '=', 'roles.id')
                ->leftJoin('permission_user', 'users.id', '=', 'permission_user.user_id')
                ->leftJoin('permissions', 'permission_user.permission_id', '=', 'permissions.id')
                ->select(
                    'order_lines.id',
                    'order_lines.product_id',
                    'order_lines.quantity',
                    'products.code as product_code',
                    'products.description as product_description',
                    'products.name as product_name',
                    'products.category_id',
                    'categories.name as category_name',
                    'roles.name as role_name',
                    'permissions.name as permission_name',
                    'companies.id as company_id',
                    'companies.name as company_name',
                    'companies.exclude_from_consolidated_report'
                )
                ->whereIn('order_lines.id', $this->orderLineIds)
                ->get();

            Log::info("Datos obtenidos", [
                'order_lines_count' => $orderLines->count()
            ]);

            // Obtener todas las categorías únicas de todos los productos
            $allCategoryIds = $orderLines->pluck('category_id')->unique()->filter();

            // Inicializar los totales de categoría para todas las categorías encontradas
            foreach ($allCategoryIds as $categoryId) {
                if (!$categoryId) continue;

                $categoryName = $orderLines->where('category_id', $categoryId)->first()->category_name ?? 'Sin Categoría';

                // Inicializar el total de la categoría
                $this->categoryTotals[$categoryId] = 0;

                // Inicializar contador de productos por categoría
                $this->categoryProductCount[$categoryId] = 0;
            }

            // Calcular los totales por categoría para todas las líneas
            foreach ($orderLines as $line) {
                $categoryId = $line->category_id;
                if (isset($this->categoryTotals[$categoryId])) {
                    $this->categoryTotals[$categoryId] += $line->quantity;
                }
            }

            Log::info("Categorías procesadas", [
                'category_totals' => $this->categoryTotals
            ]);

            // Agrupar por producto para procesar
            $productGroups = $orderLines->groupBy('product_id');

            // Para cada producto, vamos a procesar los datos
            foreach ($productGroups as $productId => $productLines) {
                if (!$productId) continue;

                $firstLine = $productLines->first();
                $productCode = $firstLine->product_code;
                $productDescription = $firstLine->product_description;
                $categoryName = $firstLine->category_name ?? 'Sin Categoría';
                $productName = $firstLine->product_name;
                $categoryId = $firstLine->category_id;

                // Inicializar datos del producto
                $productData = [
                    'category_name' => $categoryName,
                    'category_id' => $categoryId,
                    'product_code' => $productCode,
                    'product_description' => $productDescription,
                    'product_name' => $productName,
                    'cafe_consolidado' => 0,
                    'cafe_individual' => 0,
                    'convenio_consolidado' => 0,
                    'convenio_individual' => 0,
                    'suma_total' => 0
                ];

                // Añadir columnas para cada empresa excluida
                foreach ($this->excludedCompanies as $companyId => $company) {
                    $productData[$company['column_key']] = 0;
                }

                // Calcular suma total para TODAS las líneas independientemente de si están excluidas o no
                $sumaTotal = 0;

                foreach ($productLines as $line) {
                    $sumaTotal += $line->quantity;

                    // CAMBIO CLAVE: Solo procesar para columnas estándar si la empresa NO está excluida
                    if (!$line->exclude_from_consolidated_report) {
                        if ($line->role_name === 'Café' && $line->permission_name === 'Consolidado') {
                            $productData['cafe_consolidado'] += $line->quantity;
                        } elseif ($line->role_name === 'Café' && $line->permission_name === 'Individual') {
                            $productData['cafe_individual'] += $line->quantity;
                        } elseif ($line->role_name === 'Convenio' && $line->permission_name === 'Consolidado') {
                            $productData['convenio_consolidado'] += $line->quantity;
                        } elseif ($line->role_name === 'Convenio' && $line->permission_name === 'Individual') {
                            $productData['convenio_individual'] += $line->quantity;
                        }
                    }

                    // Si es una empresa excluida, calcular para columnas específicas
                    if ($line->exclude_from_consolidated_report) {
                        $companyId = $line->company_id;
                        if (isset($this->excludedCompanies[$companyId])) {
                            $columnKey = $this->excludedCompanies[$companyId]['column_key'];
                            $productData[$columnKey] += $line->quantity;
                        }
                    }
                }

                // Asignar suma total calculada
                $productData['suma_total'] = $sumaTotal;

                Log::info("Producto procesado", [
                    'product_id' => $productId,
                    'suma_total' => $sumaTotal,
                    'categorias' => [
                        'cafe_consolidado' => $productData['cafe_consolidado'],
                        'cafe_individual' => $productData['cafe_individual'],
                        'convenio_consolidado' => $productData['convenio_consolidado'],
                        'convenio_individual' => $productData['convenio_individual']
                    ],
                    'empresas_excluidas' => array_map(function ($company) use ($productData) {
                        return $productData[$company['column_key']] ?? 0;
                    }, $this->excludedCompanies)
                ]);

                // Solo contar este producto si tiene algún dato
                $hasData = $sumaTotal > 0;

                if ($hasData) {
                    if (isset($this->categoryProductCount[$categoryId])) {
                        $this->categoryProductCount[$categoryId]++;
                    }
                    $this->productsData[$productId] = $productData;
                    $this->productsProcessed[] = $productId;
                }
            }

            Log::info("Datos de productos procesados", [
                'products_count' => count($this->productsData),
                'algunas_sumas' => array_slice(array_column($this->productsData, 'suma_total'), 0, 5)
            ]);

            return $this->productsData;
        } catch (\Exception $e) {
            Log::error('Error preparando datos de productos', [
                'export_process_id' => $this->exportProcessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [];
        }
    }

    public function query()
    {
        // Preparar datos de productos primero
        $productsData = $this->prepareProductData();

        // Obtener los IDs de los productos procesados
        $productIds = $this->productsProcessed;

        // Consulta que devuelve los productos en el orden deseado
        return Product::whereIn('id', $productIds)
            ->orderBy('category_id')
            ->orderBy('code');
    }

    public function map($product): array
    {
        try {
            $productId = $product->id;
            $productsData = $this->productsData;
            $productData = $productsData[$productId] ?? null;

            if (!$productData) {
                return [];
            }

            $categoryId = $product->category_id;

            // Verificar si es el primer producto de una nueva categoría
            if ($this->lastCategoryId !== $categoryId) {
                $this->lastCategoryId = $categoryId;
                $isFirstInCategory = true;
            } else {
                $isFirstInCategory = false;
            }

            // Crear fila con datos básicos del producto
            $row = [
                'categoria' => $productData['category_name'],
                'codigo_producto' => $productData['product_code'],
                'nombre_producto' => $productData['product_name'],
                'descripcion_producto' => $productData['product_description'],
                'cafe_consolidado' => $productData['cafe_consolidado'],
                'cafe_individual' => $productData['cafe_individual'],
                'convenio_consolidado' => $productData['convenio_consolidado'],
                'convenio_individual' => $productData['convenio_individual'],
            ];

            // Añadir datos de empresas excluidas
            foreach ($this->excludedCompanies as $companyId => $company) {
                $row[$company['column_key']] = $productData[$company['column_key']];
            }

            // Suma total
            $row['suma_total'] = $productData['suma_total'];

            // Para total_categoria, solo mostrar en la primera fila de cada categoría
            $row['total_categoria'] = ($isFirstInCategory && isset($this->categoryTotals[$categoryId]))
                ? $this->categoryTotals[$categoryId]
                : '';

            // Guardar información de rowspan para la categoría
            if ($isFirstInCategory && isset($this->categoryProductCount[$categoryId]) && $this->categoryProductCount[$categoryId] > 0) {
                $this->categoryRowSpans[$categoryId] = [
                    'start_row' => 0, // Se actualizará en AfterSheet
                    'count' => $this->categoryProductCount[$categoryId]
                ];
            }

            return $row;
        } catch (\Exception $e) {
            Log::error('Error mapeando producto para exportación', [
                'export_process_id' => $this->exportProcessId,
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function headings(): array
    {
        // Asegurarse de que las empresas excluidas estén cargadas antes de devolver los headers
        $this->loadExcludedCompanies();
        return array_values($this->headers);
    }

    public function styles(Worksheet $sheet)
    {
        // Estilo para la cabecera
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2EFDA']
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
        ];

        // Estilos para las columnas de tipo
        $tipoColumnStyle = [
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9E1F2'] // Azul claro para columnas de tipo
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
        ];

        // Estilo para las columnas de empresas excluidas
        $empresaExcluidaStyle = [
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFF2CC'] // Color amarillo claro para empresas excluidas
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
        ];

        // Estilo para la columna de total categoría
        $totalCategoriaStyle = [
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FCE4D6'] // Color para el total de categoría
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
            'font' => ['bold' => true],
        ];

        $styles = [
            1 => $headerStyle,
            'E:H' => $tipoColumnStyle, // Columnas de CAFÉ y CONVENIO
        ];

        // Determinar la letra de columna para total_categoria (última columna)
        $columnCount = count($this->headers);
        $totalCategoriaColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnCount);

        // Agregar estilo para la columna de total categoría
        $styles[$totalCategoriaColumn] = $totalCategoriaStyle;

        // Agregar estilos para las columnas de empresas excluidas
        if (!empty($this->excludedCompanies)) {
            $startCol = 9; // Después de CONVENIO INDIVIDUAL que está en la columna H (8)
            $endCol = $startCol + count($this->excludedCompanies) - 1;

            if ($endCol >= $startCol) {
                $startColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($startCol);
                $endColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($endCol);
                $styles[$startColLetter . ':' . $endColLetter] = $empresaExcluidaStyle;
            }
        }

        return $styles;
    }

    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSING]);
            },
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $lastColumn = $sheet->getHighestColumn();

                // Si no hay datos (solo el encabezado), mostrar un mensaje
                if ($lastRow <= 1) {
                    $sheet->setCellValue('A2', 'No hay datos para mostrar.');
                    $sheet->mergeCells('A2:' . $lastColumn . '2');
                    $sheet->getStyle('A2:' . $lastColumn . '2')->applyFromArray([
                        'font' => ['bold' => true, 'size' => 12],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        ],
                    ]);

                    // Actualizar el estado del proceso
                    ExportProcess::where('id', $this->exportProcessId)
                        ->update(['status' => ExportProcess::STATUS_PROCESSED]);

                    return;
                }

                // Añadir borde a las celdas
                $sheet->getStyle('A1:' . $lastColumn . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                // Lógica mejorada para identificar categorías y aplicar rowspans
                $currentCategory = '';
                $categoryStartRow = 0;
                $categoryRows = [];

                // Recorrer todas las filas para identificar cada grupo de categoría
                for ($row = 2; $row <= $lastRow; $row++) {
                    $category = $sheet->getCell('A' . $row)->getValue();

                    // Si cambia la categoría o es la última fila
                    if ($category !== $currentCategory || $row === $lastRow) {
                        // Si tenemos una categoría anterior, registrar su rango
                        if ($categoryStartRow > 0 && $row > $categoryStartRow) {
                            $endRow = ($row === $lastRow && $category === $currentCategory) ? $row : $row - 1;
                            $categoryRows[] = [
                                'category' => $currentCategory,
                                'start' => $categoryStartRow,
                                'end' => $endRow,
                                'total_value' => $sheet->getCell($lastColumn . $categoryStartRow)->getValue()
                            ];
                        }

                        // Nueva categoría
                        $currentCategory = $category;
                        $categoryStartRow = $row;
                    }
                }

                // Aplicar merge a todas las categorías identificadas
                foreach ($categoryRows as $catInfo) {
                    if ($catInfo['start'] < $catInfo['end'] && !empty($catInfo['total_value'])) {
                        // Mergear la celda de Total Categoría
                        $sheet->mergeCells("{$lastColumn}{$catInfo['start']}:{$lastColumn}{$catInfo['end']}");

                        // Centrar verticalmente
                        $sheet->getStyle("{$lastColumn}{$catInfo['start']}:{$lastColumn}{$catInfo['end']}")
                            ->getAlignment()
                            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
                    }
                }

                // Destacar visualmente las categorías
                $alternateColor = false;

                foreach ($categoryRows as $catInfo) {
                    $alternateColor = !$alternateColor;

                    if ($alternateColor) {
                        // Aplicar color de fondo a todas las filas de la categoría
                        $sheet->getStyle('A' . $catInfo['start'] . ':' . $lastColumn . $catInfo['end'])->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB('F5F5F5');
                    }
                }

                // Actualizar el estado del proceso
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSED]);
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

        // Obtener el proceso actual y sus errores existentes
        $exportProcess = ExportProcess::find($this->exportProcessId);
        $existingErrors = $exportProcess->error_log ?? [];

        // Agregar el nuevo error al array existente
        $existingErrors[] = $error;

        // Actualizar el error_log en el ExportProcess
        $exportProcess->update([
            'error_log' => $existingErrors,
            'status' => ExportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::error('Error en exportación de Order Lines', [
            'export_process_id' => $this->exportProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
