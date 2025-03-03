<?php

namespace App\Exports;

use App\Models\User;
use App\Models\ExportProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeExport;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class UserDataExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithEvents,
    ShouldQueue,
    WithChunkReading
{
    use Exportable;

    private $headers = [
        'nombre' => 'Nombre',
        'correo_electronico' => 'Correo Electrónico',
        'tipo_de_usuario' => 'Tipo de Usuario',
        'tipo_de_convenio' => 'Tipo de Convenio',
        'compania' => 'Compania',
        'sucursal' => 'Sucursal',
        'validar_fecha_y_reglas_de_despacho' => 'Validar Fecha y Reglas de Despacho',
        'validar_precio_minimo' => 'Validar Precio Mínimo',
        'validar_reglas_de_subcategoria' => 'Validar Reglas de Subcategoría'
    ];

    private $exportProcessId;
    private $userIds;

    public function __construct(Collection $userIds, int $exportProcessId)
    {
        $this->userIds = $userIds;
        $this->exportProcessId = $exportProcessId;
    }

    public function query()
    {
        return User::with(['company', 'branch', 'roles', 'permissions'])
            ->whereIn('id', $this->userIds);
    }
    
    public function chunkSize(): int
    {
        return 100;
    }

    public function map($user): array
    {
        try {
            return [
                'nombre' => $user->name,
                'correo_electronico' => $user->email,
                'tipo_de_usuario' => $user->roles->isNotEmpty() ? $user->roles->first()->name : '',
                'tipo_de_convenio' => $user->permissions->isNotEmpty() ? $user->permissions->first()->name : '',
                'compania' => $user->company ? $user->company->registration_number : '',
                'sucursal' => $user->branch ? $user->branch->branch_code : '',
                'validar_fecha_y_reglas_de_despacho' => $user->allow_late_orders ? '1' : '0',
                'validar_precio_minimo' => $user->validate_min_price ? '1' : '0',
                'validar_reglas_de_subcategoria' => $user->validate_subcategory_rules ? '1' : '0',
            ];
        } catch (\Exception $e) {
            Log::error('Error mapeando usuario para exportación', [
                'export_process_id' => $this->exportProcessId,
                'user_id' => $user->id,
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
        // Configurar anchos de columna específicos
        $sheet->getColumnDimension('A')->setWidth(50); // Nombre
        $sheet->getColumnDimension('B')->setWidth(40); // Correo electrónico
        
        // Las demás columnas con ancho estándar
        for ($i = 'C'; $i <= 'I'; $i++) {
            $sheet->getColumnDimension($i)->setWidth(25);
        }
        
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2EFDA']
                ]
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
}