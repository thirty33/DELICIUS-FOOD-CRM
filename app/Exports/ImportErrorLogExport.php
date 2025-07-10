<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ImportErrorLogExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    protected $errorLog;

    public function __construct(array $errorLog)
    {
        $this->errorLog = $errorLog;
    }

    public function array(): array
    {
        return collect($this->errorLog)->map(function ($error) {
            return [
                'Fila' => $error['row'] ?? 'N/A',
                'Campo' => $error['attribute'] ?? 'N/A',
                'Errores' => isset($error['errors']) ? 
                    (is_array($error['errors']) ? implode(', ', $error['errors']) : $error['errors']) : 
                    ($error['error'] ?? 'Error desconocido'),
                'Valores' => isset($error['values']) ? 
                    collect($error['values'])
                        ->filter()
                        ->map(fn($value, $key) => "$key: $value")
                        ->implode(', ') : 
                    (isset($error['data']) ? json_encode($error['data']) : 'N/A'),
            ];
        })->toArray();
    }

    public function headings(): array
    {
        return [
            'Fila',
            'Campo',
            'Errores',
            'Valores',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}