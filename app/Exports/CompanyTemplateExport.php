<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class CompanyTemplateExport implements FromArray, WithStyles, ShouldAutoSize
{
    private $headers = [
        'company_code',
        'tax_id',
        'name',
        'business_activity',
        'fantasy_name',
        'registration_number',
        'acronym',
        'address',
        'shipping_address',
        'email',
        'phone_number',
        'website',
        'contact_name',
        'contact_last_name',
        'contact_phone_number',
        'state_region',
        'city',
        'country',
        'district',
        'postal_box',
        'zip_code',
        'payment_condition',
        'description',
        'active'
    ];

    public function array(): array
    {
        return [$this->headers];
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