<?php

namespace App\Exports;

use App\Models\ExportProcess;
use App\Models\NutritionalInformation;
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

/**
 * Nutritional Information Data Export
 *
 * Exports nutritional information to Excel format compatible with NutritionalInformationImport.
 * Headers and data structure match exactly the import requirements.
 */
class NutritionalInformationDataExport implements
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

    /**
     * Headers matching NutritionalInformationImport expected headers
     * IMPORTANT: These MUST match the import headings exactly
     */
    private $headers = [
        'codigo_de_producto' => 'CÓDIGO DE PRODUCTO',
        'nombre_de_producto' => 'NOMBRE DE PRODUCTO',
        'codigo_de_barras' => 'CODIGO DE BARRAS',
        'ingrediente' => 'INGREDIENTE',
        'alergenos' => 'ALERGENOS',
        'unidad_de_medida' => 'UNIDAD DE MEDIDA',
        'peso_neto' => 'PESO NETO',
        'peso_bruto' => 'PESO BRUTO',
        'calorias' => 'CALORIAS',
        'proteina' => 'PROTEINA',
        'grasa' => 'GRASA',
        'grasa_saturada' => 'GRASA SATURADA',
        'grasa_monoinsaturada' => 'GRASA MONOINSATURADA',
        'grasa_poliinsaturada' => 'GRASA POLIINSATURADA',
        'grasa_trans' => 'GRASA TRANS',
        'colesterol' => 'COLESTEROL',
        'carbohidrato' => 'CARBOHIDRATO',
        'fibra' => 'FIBRA',
        'azucar' => 'AZUCAR',
        'sodio' => 'SODIO',
        'alto_sodio' => 'ALTO SODIO',
        'alto_calorias' => 'ALTO CALORIAS',
        'alto_en_grasas' => 'ALTO EN GRASAS',
        'alto_en_azucares' => 'ALTO EN AZUCARES',
        'vida_util' => 'VIDA UTIL',
        'generar_etiqueta' => 'GENERAR ETIQUETA',
    ];

    private $exportProcessId;
    private $nutritionalInfoIds;

    public function __construct(Collection $nutritionalInfoIds, int $exportProcessId)
    {
        $this->nutritionalInfoIds = $nutritionalInfoIds;
        $this->exportProcessId = $exportProcessId;
    }

    public function query()
    {
        return NutritionalInformation::with(['product', 'nutritionalValues'])
            ->whereIn('id', $this->nutritionalInfoIds);
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function map($nutritionalInfo): array
    {
        try {
            return [
                'codigo_de_producto' => $nutritionalInfo->product->code,
                'nombre_de_producto' => $nutritionalInfo->product->name,
                'codigo_de_barras' => $nutritionalInfo->barcode,
                'ingrediente' => $nutritionalInfo->ingredients,
                'alergenos' => $nutritionalInfo->allergens,
                'unidad_de_medida' => $nutritionalInfo->measure_unit,
                'peso_neto' => $nutritionalInfo->net_weight,
                'peso_bruto' => $nutritionalInfo->gross_weight,
                'calorias' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::CALORIES),
                'proteina' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::PROTEIN),
                'grasa' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::FAT_TOTAL),
                'grasa_saturada' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::FAT_SATURATED),
                'grasa_monoinsaturada' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::FAT_MONOUNSATURATED),
                'grasa_poliinsaturada' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::FAT_POLYUNSATURATED),
                'grasa_trans' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::FAT_TRANS),
                'colesterol' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::CHOLESTEROL),
                'carbohidrato' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::CARBOHYDRATE),
                'fibra' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::FIBER),
                'azucar' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::SUGAR),
                'sodio' => $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::SODIUM),
                'alto_sodio' => (int) $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::HIGH_SODIUM),
                'alto_calorias' => (int) $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::HIGH_CALORIES),
                'alto_en_grasas' => (int) $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::HIGH_FAT),
                'alto_en_azucares' => (int) $nutritionalInfo->getValue(\App\Enums\NutritionalValueType::HIGH_SUGAR),
                'vida_util' => $nutritionalInfo->shelf_life_days,
                'generar_etiqueta' => $nutritionalInfo->generate_label ? 1 : 0,
            ];
        } catch (\Exception $e) {
            Log::error('Error mapping nutritional information for export', [
                'export_process_id' => $this->exportProcessId,
                'nutritional_info_id' => $nutritionalInfo->id,
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

                Log::info('Starting nutritional information export', [
                    'process_id' => $this->exportProcessId
                ]);
            },
            AfterSheet::class => function (AfterSheet $event) {
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSED]);

                Log::info('Completed nutritional information export', [
                    'process_id' => $this->exportProcessId
                ]);
            },
        ];
    }
}