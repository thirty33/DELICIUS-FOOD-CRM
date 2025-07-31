<?php

namespace App\Imports;

use App\Models\CategoryMenu;
use App\Models\Menu;
use App\Models\Category;
use App\Models\Product;
use App\Models\ImportProcess;
use App\Classes\ErrorManagment\ExportErrorHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

class CategoryMenuImport implements
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
    use Importable;

    private $importProcessId;
    private $errors = [];

    private $headingMap = [
        'titulo_del_menu' => 'menu_id',
        'nombre_de_categoria' => 'category_id',
        'mostrar_todos_los_productos' => 'show_all_products',
        'orden_de_visualizacion' => 'display_order',
        'categoria_obligatoria' => 'mandatory_category',
        'activo' => 'is_active',
        'productos' => 'products'
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    public function chunkSize(): int
    {
        return 200;
    }

    /**
     * @return array
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_PROCESSING]);

                Log::info('Iniciando importación de menús-categorías', ['process_id' => $this->importProcessId]);
            },

            AfterImport::class => function (AfterImport $event) {
                $importProcess = ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update([
                        'status' => ImportProcess::STATUS_PROCESSED
                    ]);
                }

                Log::info('Finalizada importación de menús-categorías', ['process_id' => $this->importProcessId]);
            },
        ];
    }

    /**
     * Convert Excel boolean text to PHP boolean
     */
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

    /**
     * Get validation rules for import
     * 
     * @return array
     */
    private function getValidationRules(): array
    {
        return [
            '*.titulo_del_menu' => ['required', 'string', 'exists:menus,title'],
            '*.nombre_de_categoria' => ['required', 'string', 'exists:categories,name'],
            '*.mostrar_todos_los_productos' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0,si,no,yes,no'],
            '*.orden_de_visualizacion' => ['nullable', 'integer', 'min:0'],
            '*.categoria_obligatoria' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0,si,no,yes,no'],
            '*.activo' => ['nullable'],
            '*.productos' => ['nullable', 'string']
        ];
    }

    /**
     * Get custom validation messages
     * 
     * @return array
     */
    private function getValidationMessages(): array
    {
        return [
            '*.titulo_del_menu.required' => 'El título del menú es obligatorio.',
            '*.titulo_del_menu.exists' => 'El menú especificado no existe.',
            '*.nombre_de_categoria.required' => 'El nombre de la categoría es obligatorio.',
            '*.nombre_de_categoria.exists' => 'La categoría especificada no existe.',
            '*.mostrar_todos_los_productos.in' => 'El campo mostrar todos los productos debe tener un valor válido (si/no, verdadero/falso, 1/0).',
            '*.orden_de_visualizacion.integer' => 'El orden de visualización debe ser un número entero.',
            '*.orden_de_visualizacion.min' => 'El orden de visualización debe ser un número positivo.',
            '*.categoria_obligatoria.in' => 'El campo categoría obligatoria debe tener un valor válido (si/no, verdadero/falso, 1/0).',
            '*.activo.in' => 'El campo activo debe ser VERDADERO, FALSO, 1, 0, true o false.',
        ];
    }

    /**
     * @param \Illuminate\Validation\Validator $validator
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            Log::debug('CategoryMenuImport withValidator validation data', [
                'validation_data' => count($data) > 0 ? array_slice($data, 0, 1) : [],
                'count' => count($data)
            ]);

            foreach ($data as $index => $row) {

                // Verificar que la combinación menú-categoría no exista ya
                // if (isset($row['titulo_del_menu']) && isset($row['nombre_de_categoria'])) {
                //     try {
                //         $menu = Menu::where('title', $row['titulo_del_menu'])->first();
                //         $category = Category::where('name', $row['nombre_de_categoria'])->first();

                //         if ($menu && $category) {
                //             $existingRecord = CategoryMenu::where('menu_id', $menu->id)
                //                 ->where('category_id', $category->id)
                //                 ->exists();

                //             if ($existingRecord) {
                //                 $validator->errors()->add(
                //                     "{$index}.combinacion",
                //                     'Ya existe una relación entre este menú y esta categoría.'
                //                 );
                //             }
                //         }
                //     } catch (\Exception $e) {
                //         $validator->errors()->add(
                //             "{$index}.error",
                //             'Error al validar la combinación menú-categoría: ' . $e->getMessage()
                //         );
                //     }
                // }

                // Determinar si el registro está activo
                $isActive = true; // Default value
                if (isset($row['activo']) && $row['activo'] !== null && $row['activo'] !== '') {
                    $isActive = (strtoupper(trim($row['activo'])) === 'VERDADERO' || $row['activo'] == 1 || $row['activo'] === true);
                }

                // Validar los productos especificados si no se muestran todos y el registro está activo
                if (
                    $isActive &&
                    isset($row['productos']) && !empty($row['productos']) &&
                    isset($row['mostrar_todos_los_productos']) &&
                    !$this->convertToBoolean($row['mostrar_todos_los_productos'])
                ) {

                    $productCodes = array_map('trim', explode(',', $row['productos']));
                    $category = Category::where('name', $row['nombre_de_categoria'])->first();

                    if ($category) {
                        // Verificar que todos los productos existan y pertenezcan a la categoría
                        foreach ($productCodes as $productCode) {
                            $product = Product::where('code', $productCode)->first();

                            if (!$product) {
                                $validator->errors()->add(
                                    "{$index}.productos",
                                    "El producto con código '{$productCode}' no existe."
                                );
                            } else if ($product->category_id !== $category->id) {
                                $validator->errors()->add(
                                    "{$index}.productos",
                                    "El producto con código '{$productCode}' no pertenece a la categoría '{$category->name}'."
                                );
                            }
                        }
                    }
                }

                // Si se muestra solo productos específicos, verificar que se hayan especificado productos
                // Solo validar si el registro está activo
                if (
                    $isActive &&
                    isset($row['mostrar_todos_los_productos']) &&
                    !$this->convertToBoolean($row['mostrar_todos_los_productos']) &&
                    (!isset($row['productos']) || empty($row['productos']))
                ) {

                    $validator->errors()->add(
                        "{$index}.productos",
                        'Debe especificar al menos un producto cuando no se muestran todos los productos.'
                    );
                }

                // Validate ACTIVO field accepts only VERDADERO, FALSO, 1, 0, true, false
                if (isset($row['activo']) && $row['activo'] !== null && $row['activo'] !== '') {
                    $activoValue = is_string($row['activo']) ? strtoupper(trim($row['activo'])) : $row['activo'];
                    $validValues = ['VERDADERO', 'FALSO', '1', '0', 1, 0, true, false];
                    
                    if (!in_array($activoValue, $validValues) && !in_array($row['activo'], $validValues)) {
                        $validator->errors()->add(
                            "{$index}.activo",
                            'El campo ACTIVO solo acepta los valores VERDADERO, FALSO, 1, 0, true o false.'
                        );
                    }
                }
            }
        });
    }

    /**
     * Validate and import the collection of rows
     * 
     * @param Collection $rows
     */
    public function collection(Collection $rows)
    {
        try {
            Log::info('CategoryMenuImport procesando colección', [
                'count' => $rows->count()
            ]);

            // Datos de muestra para debugging
            if ($rows->count() > 0) {
                Log::debug('Muestra de datos:', [
                    'primera_fila' => $rows->first()->toArray(),
                    'columnas' => array_keys($rows->first()->toArray())
                ]);
            }

            // Process each row
            foreach ($rows as $index => $row) {
                try {
                    // Verificar que todos los campos requeridos estén presentes y no estén vacíos
                    $requiredFields = ['titulo_del_menu', 'nombre_de_categoria'];
                    foreach ($requiredFields as $field) {
                        if (!isset($row[$field]) || empty($row[$field])) {
                            throw new \Exception("El campo {$field} es requerido y no puede estar vacío");
                        }
                    }

                    $categoryMenuData = $this->prepareCategoryMenuData($row);

                    // Crear o actualizar el registro de CategoryMenu
                    $categoryMenu = CategoryMenu::updateOrCreate(
                        [
                            'menu_id' => $categoryMenuData['menu_id'],
                            'category_id' => $categoryMenuData['category_id']
                        ],
                        $categoryMenuData
                    );

                    // Si no mostrar todos los productos y hay productos especificados, sincronizarlos
                    if (isset($categoryMenuData['products']) && !empty($categoryMenuData['products']) && !$categoryMenuData['show_all_products']) {
                        $categoryMenu->products()->sync($categoryMenuData['products']);
                    }

                    Log::info('Relación menú-categoría creada/actualizada con éxito', [
                        'menu' => $row['titulo_del_menu'],
                        'categoria' => $row['nombre_de_categoria']
                    ]);
                } catch (\Exception $e) {
                    // Usar el formato estándar de error a través de ExportErrorHandler
                    ExportErrorHandler::handle(
                        $e,
                        $this->importProcessId,
                        'row_' . ($index + 2),
                        'ImportProcess'
                    );

                    Log::error('Error procesando fila ' . ($index + 2), [
                        'error' => $e->getMessage(),
                        'data' => isset($row) ? $row->toArray() : 'No row data'
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Usar el formato estándar de error a través de ExportErrorHandler
            ExportErrorHandler::handle(
                $e,
                $this->importProcessId,
                'general_validation',
                'ImportProcess'
            );

            Log::error('Error general en la importación', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Prepare category menu data from a row
     * 
     * @param Collection $row
     * @return array
     */
    private function prepareCategoryMenuData(Collection $row): array
    {
        // Obtener el menú por título
        $menu = Menu::where('title', $row['titulo_del_menu'])->first();
        if (!$menu) {
            throw new \Exception('El menú especificado no existe.');
        }

        // Obtener la categoría por nombre
        $category = Category::where('name', $row['nombre_de_categoria'])->first();
        if (!$category) {
            throw new \Exception('La categoría especificada no existe.');
        }

        // Preparar datos básicos
        $data = [
            'menu_id' => $menu->id,
            'category_id' => $category->id,
            'show_all_products' => $this->convertToBoolean($row['mostrar_todos_los_productos'] ?? true),
            'display_order' => isset($row['orden_de_visualizacion']) && is_numeric($row['orden_de_visualizacion'])
                ? (int)$row['orden_de_visualizacion']
                : 100,
            'mandatory_category' => $this->convertToBoolean($row['categoria_obligatoria'] ?? false),
            'is_active' => isset($row['activo']) && $row['activo'] !== null && $row['activo'] !== ''
                ? (strtoupper(trim($row['activo'])) === 'VERDADERO' || $row['activo'] == 1 || $row['activo'] === true)
                : true
        ];

        // Procesar productos si no mostrar todos los productos
        if (!$data['show_all_products'] && isset($row['productos']) && !empty($row['productos'])) {
            $productCodes = array_map('trim', explode(',', $row['productos']));

            // Buscar productos que existan Y pertenezcan a la categoría especificada
            $productIds = Product::whereIn('code', $productCodes)
                ->where('category_id', $category->id)
                ->pluck('id')
                ->toArray();

            // Verificar que todos los productos especificados existan y pertenezcan a la categoría
            if (count($productIds) !== count($productCodes)) {
                Log::warning('No se encontraron todos los productos especificados o algunos no pertenecen a la categoría', [
                    'especificados' => count($productCodes),
                    'encontrados' => count($productIds),
                    'categoria' => $category->name
                ]);
            }

            $data['products'] = $productIds;
        }

        return $data;
    }

    /**
     * Validation rules
     * 
     * @return array
     */
    public function rules(): array
    {
        return $this->getValidationRules();
    }

    /**
     * Custom validation messages
     * 
     * @return array
     */
    public function customValidationMessages(): array
    {
        return $this->getValidationMessages();
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

            // Obtener el proceso actual y sus errores existentes
            $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
            $existingErrors = $importProcess->error_log ?? [];

            // Agregar el nuevo error al array existente
            $existingErrors[] = $error;

            // Actualizar el error_log en el ImportProcess
            $importProcess->update([
                'error_log' => $existingErrors,
                'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
            ]);

            Log::warning('Fallo en validación de importación de relaciones menú-categoría', [
                'import_process_id' => $this->importProcessId,
                'row_number' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ]);
        }
    }

    /**
     * Handle import errors
     * 
     * @param Throwable $e
     */
    public function onError(Throwable $e)
    {
        $error = [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];

        // Obtener el proceso actual y sus errores existentes
        $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];

        // Agregar el nuevo error al array existente
        $existingErrors[] = $error;

        // Actualizar el error_log en el ImportProcess
        $importProcess->update([
            'error_log' => $existingErrors,
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::error('Error en importación de relaciones menú-categoría', [
            'import_process_id' => $this->importProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
