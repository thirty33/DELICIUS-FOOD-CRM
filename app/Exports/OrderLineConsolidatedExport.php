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
        'descripcion_producto' => 'Descripción del Producto',
        'cantidad' => 'Cantidad',
        'cafe_consolidado' => 'CAFÉ CONSOLIDADO',
        'cafe_individual' => 'CAFÉ INDIVIDUAL',
        'convenio_consolidado' => 'CONVENIO CONSOLIDADO',
        'convenio_individual' => 'CONVENIO INDIVIDUAL',
        'suma_total' => 'Suma Total'
    ];

    private $exportProcessId;
    private $orderLineIds;
    private $productsData = null;

    public function __construct(Collection $orderLineIds, int $exportProcessId)
    {
        $this->orderLineIds = $orderLineIds;
        $this->exportProcessId = $exportProcessId;
    }
    
    /**
     * Prepara los datos agregados de productos para usar en el mapeo
     */
    private function prepareProductData()
    {
        if ($this->productsData !== null) {
            return $this->productsData;
        }
        
        try {
            $this->productsData = [];
            
            // Usar DB para reducir el consumo de memoria
            $orderLines = DB::table('order_lines')
                ->join('products', 'order_lines.product_id', '=', 'products.id')
                ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
                ->join('orders', 'order_lines.order_id', '=', 'orders.id')
                ->join('users', 'orders.user_id', '=', 'users.id')
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
                    'categories.name as category_name',
                    'roles.name as role_name',
                    'permissions.name as permission_name'
                )
                ->whereIn('order_lines.id', $this->orderLineIds)
                ->get();
            
            // Agrupar por producto_id
            $productGroups = $orderLines->groupBy('product_id');
            
            foreach ($productGroups as $productId => $lines) {
                if (!$productId) continue;
                
                $firstLine = $lines->first();
                $productCode = $firstLine->product_code;
                $productDescription = $firstLine->product_description;
                $categoryName = $firstLine->category_name ?? 'Sin Categoría';
                
                // Calcular total de cantidad
                $totalQuantity = $lines->sum('quantity');
                
                // Calcular conteos por tipo
                $cafeConsolidadoCount = $lines->filter(function ($line) {
                    return $line->role_name === 'Café' && $line->permission_name === 'Consolidado';
                })->sum('quantity');
                
                $cafeIndividualCount = $lines->filter(function ($line) {
                    return $line->role_name === 'Café' && $line->permission_name === 'Individual';
                })->sum('quantity');
                
                $convenioConsolidadoCount = $lines->filter(function ($line) {
                    return $line->role_name === 'Convenio' && $line->permission_name === 'Consolidado';
                })->sum('quantity');
                
                $convenioIndividualCount = $lines->filter(function ($line) {
                    return $line->role_name === 'Convenio' && $line->permission_name === 'Individual';
                })->sum('quantity');
                
                // Suma total de todas las categorías
                $sumaTotal = $cafeConsolidadoCount + $cafeIndividualCount + 
                             $convenioConsolidadoCount + $convenioIndividualCount;
                
                // Guardar los datos agregados por producto
                $this->productsData[$productId] = [
                    'category_name' => $categoryName,
                    'product_code' => $productCode,
                    'product_description' => $productDescription,
                    'total_quantity' => $totalQuantity,
                    'cafe_consolidado' => $cafeConsolidadoCount,
                    'cafe_individual' => $cafeIndividualCount,
                    'convenio_consolidado' => $convenioConsolidadoCount,
                    'convenio_individual' => $convenioIndividualCount,
                    'suma_total' => $sumaTotal
                ];
            }
            
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
        // Preparar datos de productos aquí para evitar cargar en el constructor
        $productsData = $this->prepareProductData();
        
        // Obtener productos únicos para mejorar el rendimiento
        $productIds = collect($productsData)->keys();
        
        return Product::whereIn('id', $productIds)
                      ->orderBy('code');
    }
    
    public function chunkSize(): int
    {
        return 100;
    }

    public function map($product): array
    {
        try {
            $productId = $product->id;
            $productsData = $this->productsData ?? $this->prepareProductData();
            $productData = $productsData[$productId] ?? null;
            
            if (!$productData) {
                return [];
            }
            
            return [
                'categoria' => $productData['category_name'],
                'codigo_producto' => $productData['product_code'],
                'descripcion_producto' => $productData['product_description'],
                'cantidad' => $productData['total_quantity'],
                'cafe_consolidado' => $productData['cafe_consolidado'],
                'cafe_individual' => $productData['cafe_individual'],
                'convenio_consolidado' => $productData['convenio_consolidado'],
                'convenio_individual' => $productData['convenio_individual'],
                'suma_total' => $productData['suma_total']
            ];
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
        
        return [
            1 => $headerStyle,
            'E:I' => $tipoColumnStyle,
        ];
    }

    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSING]);
            },
            AfterSheet::class => function (AfterSheet $event) {
                // Añadir borde a las celdas
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $lastColumn = $sheet->getHighestColumn();
                $sheet->getStyle('A1:' . $lastColumn . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                
                // Actualizar el estado del proceso
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSED]);
            },
        ];
    }
}