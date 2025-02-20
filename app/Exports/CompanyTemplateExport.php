<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class CompanyTemplateExport implements FromArray, WithStyles, ShouldAutoSize
{
    
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

    public function array(): array
    {
        return [array_values($this->headers)];
    }

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
