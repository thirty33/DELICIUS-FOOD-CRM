<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\CategoryLine;
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

class CategoryLineImport implements
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
        'categoria' => 'category_id',
        'dia_semana' => 'weekday',
        'dia_de_semana' => 'weekday',         // Actualizado
        'dias_de_preparacion' => 'preparation_days', // Actualizado
        'hora_maxima_de_pedido' => 'maximum_order_time', // Actualizado
        'activo' => 'active'
    ];

    private $daysMap = [
        'LUNES' => 'monday',
        'MARTES' => 'tuesday',
        'MIERCOLES' => 'wednesday',
        'MIÉRCOLES' => 'wednesday',
        'JUEVES' => 'thursday',
        'VIERNES' => 'friday',
        'SABADO' => 'saturday',
        'SÁBADO' => 'saturday',
        'DOMINGO' => 'sunday'
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    public function collection(Collection $rows)
    {
        try {
            Log::info('CategoryLineImport: Starting import process', ['total_rows' => $rows->count()]);
            Log::debug('Processing rows', ['rows' => $rows->toArray()]);

            Validator::make($rows->toArray(), $this->getValidationRules(), $this->getValidationMessages())->validate();

            foreach ($rows as $index => $row) {
                try {
                    Log::info('CategoryLineImport: Processing row', [
                        'row_index' => $index,
                        'categoria' => $row['categoria'] ?? 'N/A',
                        'hora_maxima_de_pedido_raw' => $row['hora_maxima_de_pedido'] ?? 'N/A',
                        'hora_maxima_de_pedido_type' => gettype($row['hora_maxima_de_pedido'] ?? null),
                        'hora_maxima_de_pedido_length' => isset($row['hora_maxima_de_pedido']) ? strlen($row['hora_maxima_de_pedido']) : 0,
                        'hora_maxima_de_pedido_dump' => isset($row['hora_maxima_de_pedido']) ? bin2hex($row['hora_maxima_de_pedido']) : 'N/A'
                    ]);

                    // Buscar la categoría por nombre
                    $category = Category::where('name', $row['categoria'])->first();

                    if (!$category) {
                        throw new \Exception("No se encontró la categoría: {$row['categoria']}");
                    }

                    $lineData = $this->prepareCategoryLineData($row, $category);
                    CategoryLine::updateOrCreate(
                        [
                            'category_id' => $lineData['category_id'],
                            'weekday' => $lineData['weekday'],
                        ],
                        $lineData
                    );
                } catch (\Exception $e) {
                    $this->handleRowError($e, $index, $row);
                }
            }
        } catch (\Exception $e) {
            $this->handleImportError($e);
        }
    }

    private function prepareCategoryLineData(Collection $row, Category $category): array
    {
        $weekdayKey = isset($row['dia_de_semana']) ? 'dia_de_semana' : 'dia_semana';
        $weekday = strtoupper(trim($row[$weekdayKey]));

        if (!isset($this->daysMap[$weekday])) {
            throw new \Exception("Día de la semana inválido: {$weekday}");
        }

        // Convertir tiempo de Excel a formato H:i
        $timeString = $this->convertExcelTimeToString($row['hora_maxima_de_pedido']);
        
        if ($timeString === false) {
            throw new \Exception("Formato de hora inválido: {$row['hora_maxima_de_pedido']}");
        }

        return [
            'category_id' => $category->id,
            'weekday' => $this->daysMap[$weekday],
            'preparation_days' => (int)$row['dias_de_preparacion'],
            'maximum_order_time' => $timeString,
            'active' => $this->convertToBoolean($row['activo'] ?? false),
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

    /**
     * Convierte un valor de tiempo de Excel a formato H:i
     * Excel almacena tiempos como fracciones de día (0.625 = 15:00, 0.75 = 18:00)
     */
    private function convertExcelTimeToString($value)
    {
        // Si ya es un string, intentar validarlo directamente
        if (is_string($value)) {
            $formats = ['H:i', 'h:i', 'G:i', 'g:i', 'H:i:s', 'g:i A', 'g:i a'];
            
            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->format('H:i');
                }
            }
            return false;
        }

        // Si es un número (formato decimal de Excel)
        if (is_numeric($value)) {
            $decimalValue = (float)$value;
            
            // Validar que el valor está en el rango válido (0.0 a 1.0)
            if ($decimalValue < 0 || $decimalValue > 1) {
                return false;
            }
            
            // Convertir fracción de día a horas y minutos
            $totalMinutes = $decimalValue * 24 * 60;
            $hours = floor($totalMinutes / 60);
            $minutes = $totalMinutes % 60;
            
            return sprintf('%02d:%02d', $hours, $minutes);
        }

        return false;
    }

    public function rules(): array
    {
        return [
            '*.categoria' => ['required', 'string', 'exists:categories,name'],
            '*.dia_de_semana' => [  // Cambiar aquí
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (!isset($this->daysMap[strtoupper(trim($value))])) {
                        $fail('El día de la semana no es válido.');
                    }
                },
            ],
            '*.dias_de_preparacion' => ['required', 'integer', 'min:0'],  // Actualizado
            '*.hora_maxima_de_pedido' => [
                'required',
                function ($attribute, $value, $fail) {
                    Log::info('CategoryLineImport: Processing time value', [
                        'attribute' => $attribute,
                        'value' => $value,
                        'value_type' => gettype($value)
                    ]);

                    // Convertir valor decimal de Excel a formato H:i
                    $timeString = $this->convertExcelTimeToString($value);
                    
                    Log::info('CategoryLineImport: Time conversion result', [
                        'original_value' => $value,
                        'converted_time' => $timeString,
                        'is_valid' => $timeString !== false
                    ]);
                    
                    if ($timeString === false) {
                        $fail('La hora máxima de pedido debe tener un formato válido.');
                    }
                }
            ],
            '*.activo' => ['nullable', 'in:VERDADERO,FALSO,true,false,1,0']
        ];
    }

    private function getValidationRules(): array
    {
        return $this->rules();
    }

    private function getValidationMessages(): array
    {
        return [
            '*.categoria.required' => 'La categoría es requerida',
            '*.categoria.exists' => 'La categoría no existe',
            '*.dia_de_semana.required' => 'El día de la semana es requerido',
            '*.dias_de_preparacion.required' => 'Los días de preparación son requeridos',
            '*.dias_de_preparacion.integer' => 'Los días de preparación deben ser un número entero',
            '*.dias_de_preparacion.min' => 'Los días de preparación no pueden ser negativos',
            '*.hora_maxima_de_pedido.required' => 'La hora máxima de pedido es requerida',
            '*.hora_maxima_de_pedido.date_format' => 'La hora máxima debe tener un formato válido (ej: 15:30, 3:30)',
            '*.activo.in' => 'El campo activo debe ser verdadero o falso',
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
        return 100;
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
}
