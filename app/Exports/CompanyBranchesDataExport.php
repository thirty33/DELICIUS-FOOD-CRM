<?php

namespace App\Exports;

use App\Imports\Concerns\BranchColumnDefinition;
use App\Models\Branch;
use App\Models\ExportProcess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Events\BeforeExport;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CompanyBranchesDataExport implements FromQuery, ShouldAutoSize, ShouldQueue, WithChunkReading, WithEvents, WithHeadings, WithMapping, WithStyles
{
    use Exportable;

    private $exportProcessId;

    private $companyIds;

    public function __construct(Collection $companyIds, int $exportProcessId)
    {
        // Extraer todas las sucursales de las empresas seleccionadas
        $this->companyIds = $companyIds;
        $this->exportProcessId = $exportProcessId;
    }

    public function query()
    {
        return Branch::whereIn('company_id', $this->companyIds)
            ->with(['company', 'dispatchRules']);
    }

    public function chunkSize(): int
    {
        return 10;
    }

    public function map($branch): array
    {
        try {
            return [
                'numero_de_registro_de_compania' => $branch->company->registration_number,
                'codigo' => $branch->branch_code,
                'nombre_de_fantasia' => $branch->fantasy_name,
                'direccion' => $branch->address,
                'direccion_de_despacho' => $branch->shipping_address,
                'nombre_de_contacto' => $branch->contact_name,
                'apellido_de_contacto' => $branch->contact_last_name,
                'telefono_de_contacto' => $branch->contact_phone_number,
                'precio_pedido_minimo' => $this->formatPrice($branch->min_price_order),
                'regla_de_transporte' => $branch->dispatchRules->first()?->name,
            ];
        } catch (\Exception $e) {
            Log::error('Error mapeando sucursal para exportación', [
                'export_process_id' => $this->exportProcessId,
                'branch_id' => $branch->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function headings(): array
    {
        return BranchColumnDefinition::headers();
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2EFDA'],
                ],
            ],
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
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSED]);
            },
        ];
    }

    /**
     * Format price to match the import format
     */
    private function formatPrice(int $price): string
    {
        $price = $price / 100; // Convert from cents to dollars

        return '$'.number_format($price, 2, '.', ',');
    }
}
