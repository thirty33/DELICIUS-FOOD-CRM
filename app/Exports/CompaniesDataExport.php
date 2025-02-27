<?php

namespace App\Exports;

use App\Models\Company;
use App\Models\ExportProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
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

class CompaniesDataExport implements
    FromCollection,
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
        'company_code' => 'Código',
        'tax_id' => 'RUT',
        'name' => 'Razón Social',
        'business_activity' => 'Giro',
        'fantasy_name' => 'Nombre de Fantasía',
        'registration_number' => 'Número de Registro',
        'acronym' => 'Sigla',
        'address' => 'Dirección',
        'shipping_address' => 'Dirección de Despacho',
        'email' => 'Email',
        'phone_number' => 'Número de Teléfono',
        'website' => 'Sitio Web',
        'contact_name' => 'Nombre de Contacto',
        'contact_last_name' => 'Apellido de Contacto',
        'contact_phone_number' => 'Teléfono de Contacto',
        'state_region' => 'Región',
        'city' => 'Ciudad',
        'country' => 'País',
        'district' => 'Comuna',
        'postal_box' => 'Casilla Postal',
        'zip_code' => 'Código Postal',
        'payment_condition' => 'Condición de Pago',
        'description' => 'Descripción',
        'active' => 'Activo'
    ];

    private $exportProcessId;
    private $companies;

    public function __construct(Collection $companies, int $exportProcessId)
    {
        $this->companies = $companies;
        $this->exportProcessId = $exportProcessId;
    }

    public function chunkSize(): int
    {
        return 1; // Procesar 100 registros a la vez
    }
    
    /**
     * @return Collection
     */
    public function collection()
    {
        return $this->companies;
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
     * @param Company $company
     * @return array
     */
    public function map($company): array
    {
        return [
            'company_code' => $company->company_code,
            'tax_id' => $company->tax_id,
            'name' => $company->name,
            'business_activity' => $company->business_activity,
            'fantasy_name' => $company->fantasy_name,
            'registration_number' => $company->registration_number,
            'acronym' => $company->acronym,
            'address' => $company->address,
            'shipping_address' => $company->shipping_address,
            'email' => $company->email,
            'phone_number' => $company->phone_number,
            'website' => $company->website,
            'contact_name' => $company->contact_name,
            'contact_last_name' => $company->contact_last_name,
            'contact_phone_number' => $company->contact_phone_number,
            'state_region' => $company->state_region,
            'city' => $company->city,
            'country' => $company->country,
            'district' => $company->district,
            'postal_box' => $company->postal_box,
            'zip_code' => $company->zip_code,
            'payment_condition' => $company->payment_condition,
            'description' => $company->description,
            'active' => $company->active ? 'VERDADERO' : 'FALSO'
        ];
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return array_values($this->headers);
    }

    /**
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
                    'startColor' => ['rgb' => 'E2EFDA']
                ]
            ],
        ];
    }
}
