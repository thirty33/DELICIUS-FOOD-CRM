<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\ImportProcess;
use App\Models\Subcategory;
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

class CategoryImport implements
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
        'nombre' => 'name',
        'descripcion' => 'description',
        'activo' => 'is_active',
        'subcategorias' => 'subcategories',
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    public function collection(Collection $rows)
    {
        try {
            Log::debug('Processing rows', ['rows' => $rows->toArray()]);

            Validator::make($rows->toArray(), $this->getValidationRules(), $this->getValidationMessages())->validate();

            foreach ($rows as $index => $row) {
                try {
                    $categoryData = $this->prepareCategoryData($row);
                    $category = Category::updateOrCreate(
                        ['name' => $categoryData['name']],
                        $categoryData
                    );

                    // Procesar subcategorías si existen
                    if (!empty($row['subcategorias'])) {
                        $subcategoryNames = explode(',', $row['subcategorias']);
                        $subcategoryIds = [];

                        foreach ($subcategoryNames as $name) {
                            $name = trim($name);
                            $subcategory = Subcategory::firstOrCreate(['name' => $name]);
                            $subcategoryIds[] = $subcategory->id;
                        }

                        $category->subcategories()->sync($subcategoryIds);
                    }
                } catch (\Exception $e) {
                    $this->handleRowError($e, $index, $row);
                }
            }
        } catch (\Exception $e) {
            $this->handleImportError($e);
        }
    }

    private function prepareCategoryData(Collection $row): array
    {
        return [
            'name' => $row['nombre'],
            'description' => $row['descripcion'] ?? null,
            'is_active' => $this->convertToBoolean($row['activo'] ?? true),
        ];
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
            '*.nombre' => ['required', 'string', 'min:2', 'max:200'],
            '*.descripcion' => ['nullable', 'string'],
            '*.activo' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0'],
            '*.subcategorias' => ['nullable', 'string'],
        ];
    }

    private function getValidationRules(): array
    {
        return $this->rules();
    }

    private function getValidationMessages(): array
    {
        return [
            '*.nombre.required' => 'El nombre es requerido',
            '*.nombre.min' => 'El nombre debe tener al menos 2 caracteres',
            '*.nombre.max' => 'El nombre no debe exceder los 200 caracteres',
            '*.activo.in' => 'El campo activo debe ser verdadero o falso',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            '*.nombre.required' => 'El campo nombre es requerido.',
            '*.nombre.string' => 'El campo nombre debe ser texto.',
            '*.nombre.min' => 'El campo nombre debe tener al menos 2 caracteres.',
            '*.nombre.max' => 'El campo nombre no debe exceder los 200 caracteres.',
            '*.activo.required' => 'El campo activo es requerido.',
            '*.activo.in' => 'El valor del campo activo no es válido. Valores permitidos: VERDADERO, FALSO.',
        ];
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

    public function chunkSize(): int
    {
        return 1;
    }

    private function handleRowError(\Exception $e, int $index, $row)
    {
        $error = [
            'row' => $index + 2,
            'data' => $row,
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

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();
            foreach ($data as $index => $row) {
                if (isset($row['nombre'])) {
                    $exists = Category::where('name', $row['nombre'])->exists();
                    if ($exists) {
                        // $validator->errors()->add(
                        //     "{$index}.nombre",
                        //     'La categoría ya existe.'
                        // );
                        continue;
                    }
                }
            }
        });
    }
}
