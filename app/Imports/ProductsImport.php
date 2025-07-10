<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Category;
use App\Models\ImportProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

class ProductsImport implements
    ToCollection,
    WithHeadingRow,
    SkipsEmptyRows,
    WithEvents,
    ShouldQueue,
    WithChunkReading,
    WithValidation,
    SkipsOnError,
    SkipsOnFailure
{
    private $importProcessId;

    private $headingMap = [
        'codigo' => 'code',
        'nombre' => 'name',
        'descripcion' => 'description',
        'precio' => 'price',
        'categoria' => 'category_id',
        'unidad_de_medida' => 'measure_unit',
        'nombre_archivo_original' => 'original_filename',
        'precio_lista' => 'price_list',
        'stock' => 'stock',
        'peso' => 'weight',
        'permitir_ventas_sin_stock' => 'allow_sales_without_stock',
        'activo' => 'active'
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    public function collection(Collection $rows)
    {
        try {
            Log::debug('Processing rows', ['rows' => $rows->toArray()]);

            Validator::make($rows->toArray(), $this->rules(), $this->getValidationMessages())->validate();

            foreach ($rows as $index => $row) {
                try {
                    // Buscar la categoría por nombre
                    $category = Category::where('name', $row['categoria'])->first();

                    if (!$category) {
                        throw new \Exception("No se encontró la categoría: {$row['categoria']}");
                    }

                    $productData = $this->prepareProductData($row, $category);
                    $ingredients = isset($productData['_ingredients']) ? $productData['_ingredients'] : [];
                    unset($productData['_ingredients']);
                    
                    // Verificar si ya existe un producto con el mismo nombre pero diferente código
                    $existingProductByName = Product::where('name', $productData['name'])
                        ->where('code', '!=', $productData['code'])
                        ->first();
                    
                    if ($existingProductByName) {
                        throw new \Exception("Ya existe un producto con el nombre '{$productData['name']}' con código '{$existingProductByName->code}'");
                    }

                    $product = Product::updateOrCreate(
                        [
                            'code' => $productData['code']
                        ],
                        $productData
                    );

                    if (!empty($ingredients)) {
                        $product->ingredients()->delete();
                        foreach ($ingredients as $ingredient) {
                            $product->ingredients()->create([
                                'descriptive_text' => trim($ingredient)
                            ]);
                        }
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
            'description' => $row['descripcion'],
            'price' => $this->transformPrice($row['precio']),
            'category_id' => $category->id,
            'measure_unit' => $row['unidad_de_medida'] ?? null,
            'original_filename' => $row['nombre_archivo_original'] ?? null,
            'price_list' => isset($row['precio_lista']) ? $this->transformPrice($row['precio_lista']) : null,
            'stock' => $row['stock'] ?? null,
            'weight' => $row['peso'] ?? null,
            'allow_sales_without_stock' => $this->convertToBoolean($row['permitir_ventas_sin_stock'] ?? false),
            'active' => $this->convertToBoolean($row['activo'] ?? false)
        ];

        if (isset($row['ingredientes']) && !empty($row['ingredientes'])) {
            $data['_ingredients'] = explode(',', $row['ingredientes']);
        }

        return $data;
    }

    private function transformPrice($price): int
    {
        if (empty($price)) {
            return 0;
        }

        // Remover el símbolo de moneda y espacios
        $price = trim(str_replace('$', '', $price));

        // Remover las comas de los miles si existen
        $price = str_replace(',', '', $price);

        // Si hay punto decimal, multiplicar por 100 para convertir a centavos
        if (strpos($price, '.') !== false) {
            return (int)(floatval($price) * 100);
        }

        return (int)$price;
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
            '*.descripcion' => ['required', 'string', 'min:2', 'max:200'],
            '*.precio' => ['required', 'string', 'regex:/^\$?[0-9]{1,3}(?:,?[0-9]{3})*(?:\.[0-9]{2})?$/'],
            '*.categoria' => ['required', 'string', 'exists:categories,name'],
            '*.unidad_de_medida' => ['nullable', 'string'],
            '*.nombre_archivo_original' => ['nullable', 'string', 'max:255'],
            '*.precio_lista' => ['nullable', 'string', 'regex:/^\$?[0-9]{1,3}(?:,?[0-9]{3})*(?:\.[0-9]{2})?$/'],
            '*.stock' => ['nullable', 'integer'],
            '*.peso' => ['nullable', 'numeric'],
            '*.permitir_ventas_sin_stock' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0'],
            '*.activo' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0'],
            '*.ingredientes' => ['nullable', 'string']
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

            '*.descripcion.required' => 'La descripción es requerida',
            '*.descripcion.string' => 'La descripción debe ser texto',
            '*.descripcion.min' => 'La descripción debe tener al menos 2 caracteres',
            '*.descripcion.max' => 'La descripción no debe exceder los 200 caracteres',

            '*.precio.required' => 'El precio es requerido',
            '*.precio.string' => 'El precio debe tener un formato válido',
            '*.precio.regex' => 'El precio debe tener un formato válido (ejemplo: $1,568.33 o 1568.33)',

            '*.categoria.required' => 'La categoría es requerida',
            '*.categoria.string' => 'La categoría debe ser texto',
            '*.categoria.exists' => 'La categoría seleccionada no existe',

            '*.unidad_de_medida.string' => 'La unidad de medida debe ser texto',

            '*.precio_lista.string' => 'El precio de lista debe tener un formato válido',
            '*.precio_lista.regex' => 'El precio de lista debe tener un formato válido (ejemplo: $1,568.33 o 1568.33)',

            '*.stock.integer' => 'El stock debe ser un número entero',

            '*.peso.numeric' => 'El peso debe ser un número',

            '*.permitir_ventas_sin_stock.in' => 'El campo permitir ventas sin stock debe ser VERDADERO o FALSO',

            '*.activo.in' => 'El campo activo debe ser VERDADERO o FALSO',
            '*.nombre_archivo_original.string' => 'El nombre de archivo original debe ser texto',
            '*.nombre_archivo_original.max' => 'El nombre de archivo original no debe exceder los 255 caracteres',
        ];
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
            'error' => $e->getMessage()
        ];

        $this->updateImportProcessError($error);
        Log::error('Error processing row', $error);
    }

    private function handleImportError(\Exception $e)
    {
        $error = [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];

        $this->updateImportProcessError($error);
        Log::error('Error in import process', $error);
    }

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
            Log::warning('Validation failure', $error);
        }
    }

    public function onError(Throwable $e)
    {
        $error = [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];

        $this->updateImportProcessError($error);
        Log::error('Import error', $error);
    }
}
