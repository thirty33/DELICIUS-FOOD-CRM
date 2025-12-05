<?php

namespace App\Imports;

use App\Contracts\PlatedDishRepositoryInterface;
use App\Enums\MeasureUnit;
use App\Models\ImportProcess;
use App\Models\PlatedDishIngredient;
use App\Repositories\ImportRecordTrackingRepository;
use App\Support\ImportExport\PlatedDishIngredientsSchema;
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

class PlatedDishIngredientsImport implements
    ToCollection,
    WithHeadingRow,
    WithEvents,
    WithValidation,
    SkipsOnError,
    SkipsOnFailure,
    ShouldQueue,
    WithChunkReading
{
    private PlatedDishRepositoryInterface $repository;
    private ImportRecordTrackingRepository $trackingRepository;
    private int $importProcessId;

    public function __construct(
        PlatedDishRepositoryInterface $repository,
        int $importProcessId
    ) {
        $this->repository = $repository;
        $this->importProcessId = $importProcessId;
        $this->trackingRepository = new ImportRecordTrackingRepository();
    }

    /**
     * Header mapping between Excel headers and internal field names.
     *
     * NOTE: This is now centralized in PlatedDishIngredientsSchema class.
     * Any changes to headers must be made in that class only.
     */

    /**
     * Prepare data for validation by normalizing measure units
     *
     * This method runs BEFORE validation, allowing us to normalize
     * Excel values (like "GRAMOS", "UNIDAD") to system values (like "GR", "UND")
     *
     * @param mixed $data
     * @param int $index
     * @return array
     */
    public function prepareForValidation($data, $index)
    {
        // Normalize measure unit if present
        if (isset($data['unidad_de_medida']) && $data['unidad_de_medida'] !== null) {
            $data['unidad_de_medida'] = MeasureUnit::mapFromExcel($data['unidad_de_medida']);
        }

        return $data;
    }

    /**
     * Process the collection of rows from Excel
     *
     * VERTICAL FORMAT:
     * - Each row represents ONE ingredient for a product
     * - Same product can have multiple rows (multiple ingredients)
     * - Products without ingredients have empty ingredient fields
     *
     * @param Collection $rows
     */
    public function collection(Collection $rows)
    {
        $groupedByProduct = $this->groupRowsByProduct($rows);

        foreach ($groupedByProduct as $productCode => $ingredientRows) {
            // Find product by code
            $product = $this->repository->findProductByCode($productCode);

            if (!$product) {
                Log::warning('Product not found during plated dish import', [
                    'product_code' => $productCode,
                    'import_process_id' => $this->importProcessId
                ]);
                continue;
            }

            // Extract is_horeca from first row (all rows have same value for product-level field)
            $isHoreca = $this->convertToBoolean($ingredientRows[0]['es_horeca'] ?? false);

            // Create or update PlatedDish (even if no ingredients)
            $platedDish = $this->repository->createOrUpdatePlatedDish($product->id, [
                'is_active' => true,
                'is_horeca' => $isHoreca,
            ]);

            // Check if product has any valid ingredients
            $hasIngredients = $this->hasValidIngredients($ingredientRows);

            if (!$hasIngredients) {
                // Product has no ingredients, but PlatedDish record was created
                continue;
            }

            // Create ingredients in order
            $orderIndex = 0;
            foreach ($ingredientRows as $row) {
                $ingredientName = $row['emplatado'] ?? null;

                // Skip empty ingredient rows
                if (empty($ingredientName) || trim($ingredientName) === '') {
                    continue;
                }

                // Normalize measure unit using enum mapping
                $rawMeasureUnit = $row['unidad_de_medida'] ?? 'UND';
                $normalizedMeasureUnit = MeasureUnit::mapFromExcel($rawMeasureUnit) ?? 'UND';

                // Extract ingredient data
                $ingredientData = [
                    'ingredient_name' => trim($ingredientName),
                    'measure_unit' => $normalizedMeasureUnit,
                    'quantity' => $row['cantidad'] ?? 0,
                    'max_quantity_horeca' => $row['cantidad_maxima_horeca'] ?? null,
                    'order_index' => $orderIndex,
                    'is_optional' => false,
                    'shelf_life' => $row['vida_util'] ?? null,
                ];

                // Create or update ingredient
                $createdIngredient = $this->repository->createOrUpdatePlatedDishIngredient(
                    $platedDish->id,
                    trim($ingredientName),
                    $ingredientData
                );

                // Track this ingredient for cleanup
                $this->trackingRepository->track(
                    $this->importProcessId,
                    'plated_dish_ingredient',
                    $platedDish->id,
                    $createdIngredient->id
                );

                $orderIndex++;
            }
        }
    }

    /**
     * Group rows by product code
     *
     * @param Collection $rows
     * @return array
     */
    private function groupRowsByProduct(Collection $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $productCode = $row['codigo_de_producto'] ?? null;

            if (!$productCode) {
                continue;
            }

            if (!isset($grouped[$productCode])) {
                $grouped[$productCode] = [];
            }

            $grouped[$productCode][] = $row;
        }

        return $grouped;
    }

    /**
     * Check if product has any valid ingredients
     *
     * @param array $ingredientRows
     * @return bool
     */
    private function hasValidIngredients(array $ingredientRows): bool
    {
        foreach ($ingredientRows as $row) {
            $ingredientCode = $row['emplatado'] ?? null;

            if (!empty($ingredientCode) && trim($ingredientCode) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the expected Excel headers (7 columns)
     *
     * @return array
     */
    public function getExpectedHeaders(): array
    {
        return PlatedDishIngredientsSchema::getHeaderValues();
    }

    /**
     * Get the heading map
     *
     * @return array
     */
    public function getHeadingMap(): array
    {
        return PlatedDishIngredientsSchema::getHeadingMap();
    }

    /**
     * Validation rules for plated dish ingredients import
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            '*.codigo_de_producto' => ['required', 'string', 'max:50'],
            '*.emplatado' => ['nullable', 'string', 'max:50'],
            '*.unidad_de_medida' => ['nullable', 'string', 'in:GR,KG,ML,L,UND'],
            '*.cantidad' => ['nullable', 'numeric', 'min:0'],
            '*.cantidad_maxima_horeca' => ['nullable', 'numeric', 'min:0'],
            '*.vida_util' => ['nullable', 'integer', 'min:1'],
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

            '*.emplatado.string' => 'El código del ingrediente debe ser texto',
            '*.emplatado.max' => 'El código del ingrediente no debe exceder 50 caracteres',

            '*.unidad_de_medida.in' => 'La unidad de medida debe ser GR, KG, ML, L o UND',

            '*.cantidad.numeric' => 'La cantidad debe ser un número',
            '*.cantidad.min' => 'La cantidad debe ser mayor o igual a 0',

            '*.cantidad_maxima_horeca.numeric' => 'La cantidad máxima HORECA debe ser un número',
            '*.cantidad_maxima_horeca.min' => 'La cantidad máxima HORECA debe ser mayor o igual a 0',

            '*.vida_util.integer' => 'La vida útil debe ser un número entero',
            '*.vida_util.min' => 'La vida útil debe ser mayor o igual a 1 día',
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

                // Validate that product code is not empty
                if (empty($productCode) || strtolower(trim($productCode)) === 'nan') {
                    $validator->errors()->add(
                        "{$index}.codigo_de_producto",
                        "El código de producto es requerido y no puede estar vacío"
                    );
                    continue;
                }

                // Validate product exists in database
                $product = $this->repository->findProductByCode($productCode);

                if (!$product) {
                    $validator->errors()->add(
                        "{$index}.codigo_de_producto",
                        "El producto con código '{$productCode}' no existe en la base de datos"
                    );
                }

                // Note: Ingredient names are free text, no validation against products table needed
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

                Log::info('Iniciando importación de emplatados', [
                    'process_id' => $this->importProcessId
                ]);
            },

            AfterImport::class => function (AfterImport $event) {
                // Clean up old ingredients that were not in the import file
                $this->cleanupOldIngredients();

                $importProcess = ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update(['status' => ImportProcess::STATUS_PROCESSED]);
                }

                Log::info('Finalizada importación de emplatados', [
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
        Log::error('Error en importación de emplatados', $error);
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

    /**
     * Clean up old ingredients that were not in the import file
     *
     * This method runs after all chunks have been processed.
     * It deletes ingredients that exist in the database but were not tracked during import.
     *
     * @return void
     */
    private function cleanupOldIngredients(): void
    {
        try {
            // Get all plated dish IDs that were affected by this import
            $affectedPlatedDishIds = $this->trackingRepository->getAffectedParentIds(
                $this->importProcessId,
                'plated_dish_ingredient'
            );

            $totalDeleted = 0;

            // For each affected plated dish, delete ingredients not in import
            foreach ($affectedPlatedDishIds as $platedDishId) {
                // Get ingredient IDs that were tracked (present in import file)
                $trackedIngredientIds = $this->trackingRepository->getTrackedRecordIdsByParent(
                    $this->importProcessId,
                    'plated_dish_ingredient',
                    $platedDishId
                );

                // Delete ingredients that were NOT tracked (not in import file)
                $deleted = PlatedDishIngredient::where('plated_dish_id', $platedDishId)
                    ->whereNotIn('id', $trackedIngredientIds)
                    ->delete();

                $totalDeleted += $deleted;

                if ($deleted > 0) {
                    Log::info('Cleaned up old plated dish ingredients', [
                        'import_process_id' => $this->importProcessId,
                        'plated_dish_id' => $platedDishId,
                        'deleted_count' => $deleted,
                    ]);
                }
            }

            // Clean up tracking records after import completion
            $this->trackingRepository->cleanup(
                $this->importProcessId,
                'plated_dish_ingredient'
            );

            Log::info('Completed plated dish ingredient cleanup', [
                'import_process_id' => $this->importProcessId,
                'total_deleted' => $totalDeleted,
                'affected_plated_dishes' => count($affectedPlatedDishIds),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cleanup old plated dish ingredients', [
                'import_process_id' => $this->importProcessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Convert Excel boolean values to PHP boolean
     *
     * Handles various Excel representations of boolean values:
     * - VERDADERO, FALSO (Spanish)
     * - TRUE, FALSE (English)
     * - 1, 0 (Numeric)
     * - SI, NO (Spanish yes/no)
     *
     * @param mixed $value Excel cell value
     * @return bool
     */
    private function convertToBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtoupper(trim($value));
            return in_array($value, ['VERDADERO', 'TRUE', 'SI', 'YES', '1']);
        }

        if (is_numeric($value)) {
            return $value == 1;
        }

        return false;
    }
}