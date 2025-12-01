<?php

namespace App\Imports;

use App\Contracts\NutritionalInformationRepositoryInterface;
use App\Enums\NutritionalValueType;
use App\Models\ImportProcess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

class NutritionalInformationImport implements
    ToCollection,
    WithHeadingRow,
    WithEvents,
    WithValidation,
    SkipsOnError,
    SkipsOnFailure,
    ShouldQueue,
    WithChunkReading
{
    private NutritionalInformationRepositoryInterface $repository;
    private int $importProcessId;

    public function __construct(NutritionalInformationRepositoryInterface $repository, int $importProcessId)
    {
        $this->repository = $repository;
        $this->importProcessId = $importProcessId;
    }
    /**
     * Header mapping between Excel headers and internal field names.
     *
     * Excel headers (Spanish, as they appear in the file):
     * - Laravel Excel converts them to snake_case automatically
     * - "CÓDIGO DE PRODUCTO" becomes "codigo_de_producto"
     * - "NOMBRE DE PRODUCTO" becomes "nombre_de_producto"
     *
     * Internal field names map to our database structure:
     * - nutritional_information table fields
     * - nutritional_values table (type + value)
     */
    private $headingMap = [
        // Product identification
        'codigo_de_producto' => 'product_code',           // Required to find product
        'nombre_de_producto' => 'product_name',           // Informational only
        'codigo_de_barras' => 'barcode',

        // Ingredients and allergens
        'ingrediente' => 'ingredients',
        'alergenos' => 'allergens',

        // Weight information
        'unidad_de_medida' => 'measure_unit',             // GR, KG, UND
        'peso_neto' => 'net_weight',
        'peso_bruto' => 'gross_weight',

        // Nutritional values per 100g (stored in nutritional_values table)
        'calorias' => 'calories',
        'proteina' => 'protein',
        'grasa' => 'fat_total',
        'grasa_saturada' => 'fat_saturated',
        'grasa_monoinsaturada' => 'fat_monounsaturated',
        'grasa_poliinsaturada' => 'fat_polyunsaturated',
        'grasa_trans' => 'fat_trans',
        'colesterol' => 'cholesterol',
        'carbohidrato' => 'carbohydrate',
        'fibra' => 'fiber',
        'azucar' => 'sugar',
        'sodio' => 'sodium',

        // High content flags (0 or 1)
        'alto_sodio' => 'high_sodium',
        'alto_calorias' => 'high_calories',
        'alto_en_grasas' => 'high_fat',
        'alto_en_azucares' => 'high_sugar',

        // Warning text flags (0 or 1)
        'mostrar_texto_soya' => 'show_soy_text',
        'mostrar_texto_pollo' => 'show_chicken_text',

        // Additional fields
        'vida_util' => 'shelf_life_days',
        'generar_etiqueta' => 'generate_label',
    ];

    /**
     * Process the collection of rows from Excel
     *
     * @param Collection $rows
     */
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            // Extract product code
            $productCode = $row['codigo_de_producto'] ?? null;

            if (!$productCode) {
                continue;
            }

            // Find product by code
            $product = $this->repository->findProductByCode($productCode);

            if (!$product) {
                continue;
            }

            // Extract nutritional_information fields
            $nutritionalInfoData = [
                'barcode' => $row['codigo_de_barras'] ?? null,
                'ingredients' => $row['ingrediente'] ?? null,
                'allergens' => $row['alergenos'] ?? null,
                'measure_unit' => $row['unidad_de_medida'] ?? 'GR',
                'net_weight' => $row['peso_neto'] ?? 0,
                'gross_weight' => $row['peso_bruto'] ?? 0,
                'shelf_life_days' => $row['vida_util'] ?? 0,
                'generate_label' => $row['generar_etiqueta'] ?? false,
                'high_sodium' => (bool) ($row['alto_sodio'] ?? false),
                'high_calories' => (bool) ($row['alto_calorias'] ?? false),
                'high_fat' => (bool) ($row['alto_en_grasas'] ?? false),
                'high_sugar' => (bool) ($row['alto_en_azucares'] ?? false),
                'show_soy_text' => (bool) ($row['mostrar_texto_soya'] ?? false),
                'show_chicken_text' => (bool) ($row['mostrar_texto_pollo'] ?? false),
            ];

            // Create or update NutritionalInformation
            $nutritionalInfo = $this->repository->createOrUpdateNutritionalInformation(
                $product->id,
                $nutritionalInfoData
            );

            // Extract nutritional_values (12 values total - numeric values only, NOT flags)
            $nutritionalValues = [
                NutritionalValueType::CALORIES->value => $row['calorias'] ?? 0,
                NutritionalValueType::PROTEIN->value => $row['proteina'] ?? 0,
                NutritionalValueType::FAT_TOTAL->value => $row['grasa'] ?? 0,
                NutritionalValueType::FAT_SATURATED->value => $row['grasa_saturada'] ?? 0,
                NutritionalValueType::FAT_MONOUNSATURATED->value => $row['grasa_monoinsaturada'] ?? 0,
                NutritionalValueType::FAT_POLYUNSATURATED->value => $row['grasa_poliinsaturada'] ?? 0,
                NutritionalValueType::FAT_TRANS->value => $row['grasa_trans'] ?? 0,
                NutritionalValueType::CHOLESTEROL->value => $row['colesterol'] ?? 0,
                NutritionalValueType::CARBOHYDRATE->value => $row['carbohidrato'] ?? 0,
                NutritionalValueType::FIBER->value => $row['fibra'] ?? 0,
                NutritionalValueType::SUGAR->value => $row['azucar'] ?? 0,
                NutritionalValueType::SODIUM->value => $row['sodio'] ?? 0,
            ];

            // Create or update NutritionalValue records
            $this->repository->createOrUpdateNutritionalValues(
                $nutritionalInfo->id,
                $nutritionalValues
            );
        }
    }

    /**
     * Get the expected Excel headers (28 columns)
     *
     * @return array
     */
    public function getExpectedHeaders(): array
    {
        return [
            'CÓDIGO DE PRODUCTO',      // 1
            'NOMBRE DE PRODUCTO',      // 2
            'CODIGO DE BARRAS',        // 3
            'INGREDIENTE',             // 4
            'ALERGENOS',               // 5
            'UNIDAD DE MEDIDA',        // 6
            'PESO NETO',               // 7
            'PESO BRUTO',              // 8
            'CALORIAS',                // 9
            'PROTEINA',                // 10
            'GRASA',                   // 11
            'GRASA SATURADA',          // 12
            'GRASA MONOINSATURADA',    // 13
            'GRASA POLIINSATURADA',    // 14
            'GRASA TRANS',             // 15
            'COLESTEROL',              // 16
            'CARBOHIDRATO',            // 17
            'FIBRA',                   // 18
            'AZUCAR',                  // 19
            'SODIO',                   // 20
            'ALTO SODIO',              // 21
            'ALTO CALORIAS',           // 22
            'ALTO EN GRASAS',          // 23
            'ALTO EN AZUCARES',        // 24
            'VIDA UTIL',               // 25
            'GENERAR ETIQUETA',        // 26
            'MOSTRAR TEXTO SOYA',      // 27
            'MOSTRAR TEXTO POLLO',     // 28
        ];
    }

    /**
     * Get the heading map
     *
     * @return array
     */
    public function getHeadingMap(): array
    {
        return $this->headingMap;
    }

    /**
     * Validation rules for nutritional information import
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            '*.codigo_de_producto' => ['required', 'string', 'max:50'],
            '*.codigo_de_barras' => ['required', 'string', 'max:20'],
            '*.ingrediente' => ['nullable', 'string'],
            '*.alergenos' => ['nullable', 'string'],
            '*.unidad_de_medida' => ['required', 'string', 'in:GR,KG,UND'],
            '*.peso_neto' => ['nullable', 'numeric', 'min:0'],
            '*.peso_bruto' => ['nullable', 'numeric', 'min:0'],

            // Nutritional values - must be numeric
            '*.calorias' => ['nullable', 'numeric', 'min:0'],
            '*.proteina' => ['nullable', 'numeric', 'min:0'],
            '*.grasa' => ['nullable', 'numeric', 'min:0'],
            '*.grasa_saturada' => ['nullable', 'numeric', 'min:0'],
            '*.grasa_monoinsaturada' => ['nullable', 'numeric', 'min:0'],
            '*.grasa_poliinsaturada' => ['nullable', 'numeric', 'min:0'],
            '*.grasa_trans' => ['nullable', 'numeric', 'min:0'],
            '*.colesterol' => ['nullable', 'numeric', 'min:0'],
            '*.carbohidrato' => ['nullable', 'numeric', 'min:0'],
            '*.fibra' => ['nullable', 'numeric', 'min:0'],
            '*.azucar' => ['nullable', 'numeric', 'min:0'],
            '*.sodio' => ['nullable', 'numeric', 'min:0'],

            // Flags - must be 0 or 1
            '*.alto_sodio' => ['nullable', 'in:0,1'],
            '*.alto_calorias' => ['nullable', 'in:0,1'],
            '*.alto_en_grasas' => ['nullable', 'in:0,1'],
            '*.alto_en_azucares' => ['nullable', 'in:0,1'],

            // Warning text flags - must be 0 or 1
            '*.mostrar_texto_soya' => ['nullable', 'in:0,1'],
            '*.mostrar_texto_pollo' => ['nullable', 'in:0,1'],

            '*.vida_util' => ['nullable', 'integer', 'min:0'],
            '*.generar_etiqueta' => ['nullable', 'in:0,1'],
        ];
    }

    /**
     * Custom validation messages
     *
     * @return array
     */
    public function customValidationMessages(): array
    {
        return [
            '*.codigo_de_producto.required' => 'El código de producto es requerido',
            '*.codigo_de_producto.string' => 'El código de producto debe ser texto',
            '*.codigo_de_producto.max' => 'El código de producto no debe exceder 50 caracteres',

            '*.codigo_de_barras.required' => 'El código de barras es requerido',
            '*.codigo_de_barras.string' => 'El código de barras debe ser texto',
            '*.codigo_de_barras.max' => 'El código de barras no debe exceder 20 caracteres',

            '*.unidad_de_medida.required' => 'La unidad de medida es requerida',
            '*.unidad_de_medida.in' => 'La unidad de medida debe ser GR, KG o UND',

            '*.peso_neto.numeric' => 'El peso neto debe ser un número',
            '*.peso_neto.min' => 'El peso neto debe ser mayor o igual a 0',

            '*.peso_bruto.numeric' => 'El peso bruto debe ser un número',
            '*.peso_bruto.min' => 'El peso bruto debe ser mayor o igual a 0',

            // Nutritional values
            '*.calorias.numeric' => 'Las calorías deben ser un valor numérico',
            '*.calorias.min' => 'Las calorías deben ser mayor o igual a 0',

            '*.proteina.numeric' => 'La proteína debe ser un valor numérico',
            '*.proteina.min' => 'La proteína debe ser mayor o igual a 0',

            '*.grasa.numeric' => 'La grasa total debe ser un valor numérico',
            '*.grasa.min' => 'La grasa total debe ser mayor o igual a 0',

            '*.grasa_saturada.numeric' => 'La grasa saturada debe ser un valor numérico',
            '*.grasa_saturada.min' => 'La grasa saturada debe ser mayor o igual a 0',

            '*.grasa_monoinsaturada.numeric' => 'La grasa monoinsaturada debe ser un valor numérico',
            '*.grasa_monoinsaturada.min' => 'La grasa monoinsaturada debe ser mayor o igual a 0',

            '*.grasa_poliinsaturada.numeric' => 'La grasa poliinsaturada debe ser un valor numérico',
            '*.grasa_poliinsaturada.min' => 'La grasa poliinsaturada debe ser mayor o igual a 0',

            '*.grasa_trans.numeric' => 'La grasa trans debe ser un valor numérico',
            '*.grasa_trans.min' => 'La grasa trans debe ser mayor o igual a 0',

            '*.colesterol.numeric' => 'El colesterol debe ser un valor numérico',
            '*.colesterol.min' => 'El colesterol debe ser mayor o igual a 0',

            '*.carbohidrato.numeric' => 'Los carbohidratos deben ser un valor numérico',
            '*.carbohidrato.min' => 'Los carbohidratos deben ser mayor o igual a 0',

            '*.fibra.numeric' => 'La fibra debe ser un valor numérico',
            '*.fibra.min' => 'La fibra debe ser mayor o igual a 0',

            '*.azucar.numeric' => 'El azúcar debe ser un valor numérico',
            '*.azucar.min' => 'El azúcar debe ser mayor o igual a 0',

            '*.sodio.numeric' => 'El sodio debe ser un valor numérico',
            '*.sodio.min' => 'El sodio debe ser mayor o igual a 0',

            // Flags
            '*.alto_sodio.in' => 'Alto en sodio debe ser 0 o 1',
            '*.alto_calorias.in' => 'Alto en calorías debe ser 0 o 1',
            '*.alto_en_grasas.in' => 'Alto en grasas debe ser 0 o 1',
            '*.alto_en_azucares.in' => 'Alto en azúcares debe ser 0 o 1',

            // Warning text flags
            '*.mostrar_texto_soya.in' => 'Mostrar texto soya debe ser 0 o 1',
            '*.mostrar_texto_pollo.in' => 'Mostrar texto pollo debe ser 0 o 1',

            '*.vida_util.integer' => 'La vida útil debe ser un número entero',
            '*.vida_util.min' => 'La vida útil debe ser mayor o igual a 0',

            '*.generar_etiqueta.in' => 'Generar etiqueta debe ser 0 o 1',
        ];
    }

    /**
     * Additional validation logic
     *
     * @param \Illuminate\Validation\Validator $validator
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            foreach ($data as $index => $row) {
                $productCode = $row['codigo_de_producto'] ?? null;

                // Validate that product code is not empty (catches null, empty string, 'nan' from pandas)
                if (empty($productCode) || strtolower(trim($productCode)) === 'nan') {
                    $validator->errors()->add(
                        "{$index}.codigo_de_producto",
                        "El código de producto es requerido y no puede estar vacío"
                    );
                    continue; // Skip further validation for this row
                }

                // Validate product exists in database
                $product = $this->repository->findProductByCode($productCode);

                if (!$product) {
                    $validator->errors()->add(
                        "{$index}.codigo_de_producto",
                        "El producto con código '{$productCode}' no existe en la base de datos"
                    );
                }
            }
        });
    }

    /**
     * Register events for import process
     *
     * @return array
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_PROCESSING]);

                Log::info('Iniciando importación de información nutricional', [
                    'process_id' => $this->importProcessId
                ]);
            },

            AfterImport::class => function (AfterImport $event) {
                $importProcess = ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update(['status' => ImportProcess::STATUS_PROCESSED]);
                }

                Log::info('Finalizada importación de información nutricional', [
                    'process_id' => $this->importProcessId
                ]);
            },
        ];
    }

    /**
     * Handle validation failures
     *
     * @param Failure ...$failures
     */
    public function onFailure(Failure ...$failures)
    {
        foreach ($failures as $failure) {
            $error = [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ];

            $this->updateImportProcessError($error);
        }
    }

    /**
     * Handle general errors
     *
     * @param Throwable $e
     */
    public function onError(Throwable $e)
    {
        $error = [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];

        $this->updateImportProcessError($error);
        Log::error('Error en importación de información nutricional', $error);
    }

    /**
     * Chunk size for processing imports
     *
     * @return int
     */
    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * Update import process with error
     *
     * @param array $error
     */
    private function updateImportProcessError(array $error)
    {
        $importProcess = ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];
        $existingErrors[] = $error;

        $importProcess->update([
            'error_log' => $existingErrors,
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);
    }
}
