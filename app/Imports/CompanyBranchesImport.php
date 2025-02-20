<?php

namespace App\Imports;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

class CompanyBranchesImport implements
    ToCollection,
    WithHeadingRow,
    WithValidation,
    SkipsOnError,
    SkipsOnFailure,
    SkipsEmptyRows,
    WithEvents,
    ShouldQueue,
    WithChunkReading
{
    private $importProcessId;

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    public function collection(Collection $rows)
    {
        try {
            Log::debug('Processing rows', ['rows' => $rows->toArray()]);

            Validator::make($rows->toArray(), $this->getValidationRules(), $this->customValidationMessages());

            foreach ($rows as $index => $row) {
                try {
                    // Buscar la compañía por número de registro
                    $company = Company::where('registration_number', $row['numero_de_registro_de_compania'])->first();

                    if (!$company) {
                        throw new \Exception("No se encontró la empresa con número de registro: {$row['numero_de_registro_de_compania']}");
                    }

                    // Preparar datos de la sucursal
                    $branchData = [
                        'company_id' => $company->id,
                        'branch_code' => $row['codigo'],
                        'fantasy_name' => $row['nombre_de_fantasia'],
                        'address' => $row['direccion'],
                        'shipping_address' => $row['direccion_de_despacho'] ?? null,
                        'contact_name' => $row['nombre_de_contacto'] ?? null,
                        'contact_last_name' => $row['apellido_de_contacto'] ?? null,
                        'contact_phone_number' => $row['telefono_de_contacto'] ?? null,
                        'min_price_order' => $row['precio_pedido_minimo'],
                    ];

                    $branchData = $this->prepareBranchData($row, $company);

                    Branch::updateOrCreate(
                        ['branch_code' => $row['codigo']],
                        $branchData
                    );
                } catch (\Exception $e) {
                    $this->handleRowError($e, $index, $row);
                }
            }
        } catch (\Exception $e) {
            $this->handleImportError($e);
        }
    }

    public function rules(): array
    {
        return [
            'numero_de_registro_de_compania' => ['required', 'string', 'exists:companies,registration_number'],
            'codigo' => ['required', 'string', 'min:2', 'max:50'],
            'nombre_de_fantasia' => ['required', 'string', 'min:2', 'max:200'],
            'direccion' => ['required', 'string', 'min:2', 'max:200'],
            'direccion_de_despacho' => ['nullable', 'string'],
            'nombre_de_contacto' => ['nullable', 'string'],
            'apellido_de_contacto' => ['nullable', 'string'],
            'telefono_de_contacto' => ['nullable', 'string'],
            // 'precio_pedido_minimo' => ['required', 'numeric', 'min:0'],
            'precio_pedido_minimo' => [
                'required',
                'string',
                'regex:/^\$?[0-9]{1,3}(?:,?[0-9]{3})*(?:\.[0-9]{2})?$/'
            ],
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'numero_de_registro_de_compania.required' => 'El número de registro de la compañía es requerido',
            'numero_de_registro_de_compania.exists' => 'La empresa no existe',
            'codigo.required' => 'El código de sucursal es requerido',
            'nombre_de_fantasia.required' => 'El nombre de fantasía es requerido',
            'direccion.required' => 'La dirección es requerida',
            'precio_pedido_minimo.required' => 'El precio de pedido mínimo es requerido',
            'precio_pedido_minimo.regex' => 'El precio debe tener un formato válido (ejemplo: $1,568.33 o 1568.33)',
        ];
    }

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_QUEUED]);
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

    /**
     * @param \Illuminate\Validation\Validator $validator
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();
            foreach ($data as $index => $row) {
                if (isset($row['codigo'])) {
                    $existingBranch = Branch::where('branch_code', $row['codigo'])
                        ->when(isset($row['numero_de_registro_de_compania']), function ($query) use ($row) {
                            $query->whereHas('company', function ($q) use ($row) {
                                $q->where('registration_number', '!=', $row['numero_de_registro_de_compania']);
                            });
                        })
                        ->exists();

                    if ($existingBranch) {
                        $validator->errors()->add(
                            "{$index}.codigo",
                            'El código de sucursal ya existe en otra empresa.'
                        );
                    }
                }
            }
        });
    }

    /**
     * Get validation rules
     */
    private function getValidationRules(): array
    {
        return [
            '*.numero_de_registro_de_compania' => ['required', 'string', 'exists:companies,registration_number'],
            '*.codigo' => ['required', 'string', 'min:2', 'max:50'],
            '*.nombre_de_fantasia' => ['required', 'string', 'min:2', 'max:200'],
            '*.direccion' => ['required', 'string', 'min:2', 'max:200'],
            '*.direccion_de_despacho' => ['nullable', 'string'],
            '*.nombre_de_contacto' => ['nullable', 'string'],
            '*.apellido_de_contacto' => ['nullable', 'string'],
            '*.telefono_de_contacto' => ['nullable', 'string'],
            '*.precio_pedido_minimo' => [
                'required',
                'string',
                'regex:/^\$?[0-9]{1,3}(?:,?[0-9]{3})*(?:\.[0-9]{2})?$/'
            ],
        ];
    }

    /**
     * Prepare branch data from a row
     * 
     * @param Collection $row
     * @param Company $company
     * @return array
     */
    private function prepareBranchData(Collection $row, Company $company): array
    {
        return [
            'company_id' => $company->id,
            'branch_code' => $row['codigo'],
            'fantasy_name' => $row['nombre_de_fantasia'],
            'address' => $row['direccion'],
            'shipping_address' => $row['direccion_de_despacho'] ?? null,
            'contact_name' => $row['nombre_de_contacto'] ?? null,
            'contact_last_name' => $row['apellido_de_contacto'] ?? null,
            'contact_phone_number' => $row['telefono_de_contacto'] ?? null,
            'min_price_order' => $this->transformPrice($row['precio_pedido_minimo']),
        ];
    }

    /**
     * Transforma un precio con formato de visualización a entero
     * Ejemplo: "$1,568.33" -> 156833
     */
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
}
