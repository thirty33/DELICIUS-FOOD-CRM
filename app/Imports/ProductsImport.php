<?php

namespace App\Imports;

use App\Actions\Products\SyncMasterCategoriesAction;
use App\Imports\Concerns\ProductColumnDefinition;
use App\Models\Category;
use App\Models\ImportProcess;
use App\Models\Product;
use App\Models\ProductionArea;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

class ProductsImport implements ShouldQueue, SkipsEmptyRows, SkipsOnError, SkipsOnFailure, ToCollection, WithChunkReading, WithEvents, WithHeadingRow, WithValidation
{
    private $importProcessId;

    private $headingMap = ProductColumnDefinition::HEADING_MAP;

    private SyncMasterCategoriesAction $syncMasterCategoriesAction;

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
        $this->syncMasterCategoriesAction = app(SyncMasterCategoriesAction::class);
    }

    public function collection(Collection $rows)
    {
        try {

            Validator::make($rows->toArray(), $this->rules(), $this->getValidationMessages())->validate();

            foreach ($rows as $index => $row) {
                try {
                    // Find category by name
                    $category = Category::where('name', $row['categoria'])->first();

                    if (! $category) {
                        throw new \Exception("No se encontró la categoría: {$row['categoria']}");
                    }

                    $productData = $this->prepareProductData($row, $category);
                    $ingredients = isset($productData['_ingredients']) ? $productData['_ingredients'] : [];
                    $productionAreas = isset($productData['_production_areas']) ? $productData['_production_areas'] : [];
                    $masterCategories = isset($productData['_master_categories']) ? $productData['_master_categories'] : null;
                    unset($productData['_ingredients']);
                    unset($productData['_production_areas']);
                    unset($productData['_master_categories']);

                    $product = Product::updateOrCreate(
                        [
                            'code' => $productData['code'],
                        ],
                        $productData
                    );

                    if (! empty($ingredients)) {
                        $product->ingredients()->delete();
                        foreach ($ingredients as $ingredient) {
                            $product->ingredients()->create([
                                'descriptive_text' => trim($ingredient),
                            ]);
                        }
                    }

                    // Handle production areas
                    if (! empty($productionAreas)) {
                        $productionAreaIds = [];
                        foreach ($productionAreas as $areaName) {
                            $areaName = trim($areaName);
                            if (! empty($areaName)) {
                                // Find or create production area
                                $productionArea = ProductionArea::firstOrCreate(
                                    ['name' => $areaName]
                                );
                                $productionAreaIds[] = $productionArea->id;
                            }
                        }

                        // Sync production areas (removes old, adds new)
                        if (! empty($productionAreaIds)) {
                            $product->productionAreas()->sync($productionAreaIds);
                        }
                    }

                    // Handle master categories
                    if (! empty($masterCategories)) {
                        $this->syncMasterCategoriesAction->execute($category, $masterCategories);
                    }

                } catch (\Exception $e) {
                    $this->handleRowError($e, $index, $row);
                }
            }
        } catch (\Exception $e) {
            $this->handleImportError($e);
        }
    }

    private function prepareProductData(Collection $row, Category $category): array
    {
        $data = [
            'code' => $row['codigo'],
            'name' => $row['nombre'],
            'description' => $this->handleEmptyField($row['descripcion'] ?? null, 'No description'),
            'price' => $this->handleEmptyPriceField($row['precio'] ?? null),
            'category_id' => $category->id,
            'measure_unit' => $row['unidad_de_medida'] ?? null,
            'original_filename' => $row['nombre_archivo_original'] ?? null,
            'price_list' => $this->handleEmptyPriceField($row['precio_lista'] ?? null),
            'stock' => $this->handleStockField($row['stock'] ?? null),
            'weight' => $this->handleWeightField($row['peso'] ?? null),
            'allow_sales_without_stock' => $this->convertToBoolean($row['permitir_ventas_sin_stock'] ?? false),
            'active' => $this->convertToBoolean($row['activo'] ?? false),
        ];

        if (! empty($row['codigo_de_facturacion'])) {
            $data['billing_code'] = $row['codigo_de_facturacion'];
        }

        if (isset($row['orden']) && $row['orden'] !== null && $row['orden'] !== '') {
            $data['display_order'] = (int) $row['orden'];
        }

        if (isset($row['ingredientes']) && ! empty($row['ingredientes'])) {
            $data['_ingredients'] = explode(',', $row['ingredientes']);
        }

        if (isset($row['areas_de_produccion']) && ! empty($row['areas_de_produccion']) && $row['areas_de_produccion'] !== '-') {
            $data['_production_areas'] = explode(',', $row['areas_de_produccion']);
        }

        if (isset($row['categoria_maestra']) && ! empty($row['categoria_maestra']) && $row['categoria_maestra'] !== '-') {
            $data['_master_categories'] = $row['categoria_maestra'];
        }

        return $data;
    }

    private function transformPrice($price): ?int
    {
        if (empty($price) || $price === '-') {
            return null;
        }

        // Remove currency symbol and spaces
        $price = trim(str_replace('$', '', $price));

        // Remove thousands commas if they exist
        $price = str_replace(',', '', $price);

        // If there's decimal point, multiply by 100 to convert to cents
        if (strpos($price, '.') !== false) {
            return (int) (floatval($price) * 100);
        }

        return (int) $price;
    }

    private function handleEmptyField($value, $default = null): ?string
    {
        if (empty($value) || $value === '-') {
            return $default;
        }

        return $value;
    }

    private function handleEmptyPriceField($value): ?int
    {
        if (empty($value) || $value === '-') {
            return null;
        }

        return $this->transformPrice($value);
    }

    private function handleEmptyIntegerField($value): ?int
    {
        if (empty($value) || $value === '-') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function handleEmptyNumericField($value): ?float
    {
        if (empty($value) || $value === '-') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function handleStockField($value): int
    {
        // If empty or '-', return 0
        if (empty($value) || $value === '-') {
            return 0;
        }

        // Clean value from spaces
        $cleanValue = trim($value);

        // Remove leading apostrophe if present (from Excel text formatting)
        if (str_starts_with($cleanValue, "'")) {
            $cleanValue = substr($cleanValue, 1);
        }

        // Convert to integer (already validated in withValidator)
        return (int) $cleanValue;
    }

    private function handleWeightField($value): float
    {
        // If empty or '-', return 0
        if (empty($value) || $value === '-') {
            return 0.0;
        }

        // Clean value from spaces
        $cleanValue = trim($value);

        // Remove leading apostrophe if present (from Excel text formatting)
        if (str_starts_with($cleanValue, "'")) {
            $cleanValue = substr($cleanValue, 1);
        }

        // Convert to decimal number (already validated in withValidator)
        return (float) $cleanValue;
    }

    private function convertToBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));

            return in_array($value, ['true', 'verdadero', 'si', 'yes', '1', 'activo']);
        }

        if (is_numeric($value)) {
            return $value == 1;
        }

        return false;
    }

    public function rules(): array
    {
        return [
            '*.codigo' => ['required', 'string', 'min:2', 'max:50'],
            '*.nombre' => ['required', 'string', 'min:2', 'max:200'],
            '*.descripcion' => ['nullable', 'string', 'max:200'],
            '*.precio' => ['nullable', 'string', 'regex:/^(\$?[0-9]{1,3}(?:,?[0-9]{3})*(?:\.[0-9]{2})?|-)$/'],
            '*.categoria' => ['required', 'string', 'exists:categories,name'],
            '*.unidad_de_medida' => ['nullable', 'string'],
            '*.nombre_archivo_original' => ['nullable', 'string', 'max:255'],
            '*.precio_lista' => ['nullable', 'string', 'regex:/^(\$?[0-9]{1,3}(?:,?[0-9]{3})*(?:\.[0-9]{2})?|-)$/'],
            '*.stock' => ['nullable', 'string'],
            '*.peso' => ['nullable', 'string'],
            '*.permitir_ventas_sin_stock' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0'],
            '*.activo' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0'],
            '*.ingredientes' => ['nullable', 'string'],
            '*.areas_de_produccion' => ['nullable', 'string'],
        ];
    }

    private function getValidationMessages(): array
    {
        return [
            '*.codigo.required' => 'El código es requerido',
            '*.codigo.string' => 'El código debe ser texto',
            '*.codigo.min' => 'El código debe tener al menos 2 caracteres',
            '*.codigo.max' => 'El código no debe exceder los 50 caracteres',

            '*.nombre.required' => 'El nombre es requerido',
            '*.nombre.string' => 'El nombre debe ser texto',
            '*.nombre.min' => 'El nombre debe tener al menos 2 caracteres',
            '*.nombre.max' => 'El nombre no debe exceder los 200 caracteres',

            '*.descripcion.string' => 'La descripción debe ser texto',
            '*.descripcion.max' => 'La descripción no debe exceder los 200 caracteres',

            '*.precio.string' => 'El precio debe tener un formato válido',
            '*.precio.regex' => 'El precio debe tener un formato válido (ejemplo: $1,568.33, 1568.33 o "-" para vacío)',

            '*.categoria.required' => 'La categoría es requerida',
            '*.categoria.string' => 'La categoría debe ser texto',
            '*.categoria.exists' => 'La categoría seleccionada no existe',

            '*.unidad_de_medida.string' => 'La unidad de medida debe ser texto',

            '*.precio_lista.string' => 'El precio de lista debe tener un formato válido',
            '*.precio_lista.regex' => 'El precio de lista debe tener un formato válido (ejemplo: $1,568.33, 1568.33 o "-" para vacío)',

            '*.stock.string' => 'El stock debe ser un valor válido',

            '*.peso.string' => 'El peso debe ser un valor válido',

            '*.permitir_ventas_sin_stock.in' => 'El campo permitir ventas sin stock debe ser VERDADERO o FALSO',

            '*.activo.in' => 'El campo activo debe ser VERDADERO o FALSO',
            '*.nombre_archivo_original.string' => 'El nombre de archivo original debe ser texto',
            '*.nombre_archivo_original.max' => 'El nombre de archivo original no debe exceder los 255 caracteres',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            foreach ($data as $index => $row) {
                // Validate stock
                if (isset($row['stock']) && ! empty($row['stock']) && $row['stock'] !== '-') {
                    $cleanStock = trim($row['stock']);
                    // Remove leading apostrophe if present (from Excel text formatting)
                    if (str_starts_with($cleanStock, "'")) {
                        $cleanStock = substr($cleanStock, 1);
                    }
                    if (! is_numeric($cleanStock)) {
                        $validator->errors()->add(
                            "{$index}.stock",
                            "El campo stock debe ser un número entero válido. Valor recibido: '{$row['stock']}'"
                        );
                    }
                }

                // Validate weight
                if (isset($row['peso']) && ! empty($row['peso']) && $row['peso'] !== '-') {
                    $cleanPeso = trim($row['peso']);
                    // Remove leading apostrophe if present (from Excel text formatting)
                    if (str_starts_with($cleanPeso, "'")) {
                        $cleanPeso = substr($cleanPeso, 1);
                    }
                    if (! is_numeric($cleanPeso)) {
                        $validator->errors()->add(
                            "{$index}.peso",
                            "El campo peso debe ser un número válido. Valor recibido: '{$row['peso']}'"
                        );
                    }
                }
            }
        });
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_PROCESSING]);
            },
            AfterImport::class => function (AfterImport $event) {
                $importProcess = ImportProcess::find($this->importProcessId);
                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update(['status' => ImportProcess::STATUS_PROCESSED]);
                }
            },
        ];
    }

    private function handleRowError(\Exception $e, int $index, $row)
    {
        $error = [
            'row' => $index + 2,
            'data' => $row->toArray(),
            'error' => $e->getMessage(),
        ];

        $this->updateImportProcessError($error);
    }

    private function handleImportError(\Exception $e)
    {
        $error = [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];

        $this->updateImportProcessError($error);

    }

    private function updateImportProcessError(array $error)
    {
        $importProcess = ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];
        $existingErrors[] = $error;

        $importProcess->update([
            'error_log' => $existingErrors,
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
        ]);
    }

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

    public function onError(Throwable $e)
    {
        $error = [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];

        $this->updateImportProcessError($error);
        Log::error('Import error', $error);
    }
}
