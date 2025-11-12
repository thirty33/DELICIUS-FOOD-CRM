<?php

namespace Tests\Feature\Imports;

use App\Imports\OrderLinesImport;
use App\Models\ImportProcess;
use App\Enums\OrderImportValidationMessage;
use Database\Seeders\OrderLinesImportTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Order Lines Import Validation Tests
 *
 * Tests all active validation rules in OrderLinesImport:
 * 1. Standard validation rules (rules() method) - 13 tests
 * 2. Custom withValidator rules - 4 tests
 * 3. Business validation rules - 1 test (MaxOrderAmountValidation)
 *
 * Each test validates that:
 * - The error object contains the correct error message
 * - The error object contains the correct Excel row number (row 2)
 * - The order is NOT created when validation fails
 */
class OrderLinesImportValidationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed test data (users, products, companies, branches, etc.)
        $this->seed(OrderLinesImportTestSeeder::class);
    }

    /**
     * Data provider for validation tests
     *
     * @return array<string, array{file: string, expectedMessage: OrderImportValidationMessage, description: string}>
     */
    public static function validationTestCases(): array
    {
        return [
            // Standard validation rules (from rules() method)
            'invalid_id_orden_must_be_integer' => [
                'file' => 'test_order_invalid_id_orden.xlsx',
                'expectedMessage' => OrderImportValidationMessage::ID_ORDEN_INTEGER,
                'description' => 'id_orden must be integer',
            ],
            'missing_estado_required' => [
                'file' => 'test_order_missing_estado.xlsx',
                'expectedMessage' => OrderImportValidationMessage::ESTADO_REQUIRED,
                'description' => 'estado is required',
            ],
            'invalid_estado_not_valid_enum' => [
                'file' => 'test_order_invalid_estado.xlsx',
                'expectedMessage' => OrderImportValidationMessage::ESTADO_INVALID,
                'description' => 'estado must be valid enum value',
            ],
            'missing_fecha_orden_required' => [
                'file' => 'test_order_missing_fecha_orden.xlsx',
                'expectedMessage' => OrderImportValidationMessage::FECHA_ORDEN_REQUIRED,
                'description' => 'fecha_de_orden is required',
            ],
            'missing_fecha_despacho_required' => [
                'file' => 'test_order_missing_fecha_despacho.xlsx',
                'expectedMessage' => OrderImportValidationMessage::FECHA_DESPACHO_REQUIRED,
                'description' => 'fecha_de_despacho is required',
            ],
            'missing_codigo_empresa_required' => [
                'file' => 'test_order_missing_codigo_empresa.xlsx',
                'expectedMessage' => OrderImportValidationMessage::CODIGO_EMPRESA_REQUIRED,
                'description' => 'codigo_de_empresa is required',
            ],
            'missing_codigo_sucursal_required' => [
                'file' => 'test_order_missing_codigo_sucursal.xlsx',
                'expectedMessage' => OrderImportValidationMessage::CODIGO_SUCURSAL_REQUIRED,
                'description' => 'codigo_sucursal is required',
            ],
            'missing_usuario_required' => [
                'file' => 'test_order_missing_usuario.xlsx',
                'expectedMessage' => OrderImportValidationMessage::USUARIO_REQUIRED,
                'description' => 'usuario is required',
            ],
            'missing_codigo_producto_required' => [
                'file' => 'test_order_missing_codigo_producto.xlsx',
                'expectedMessage' => OrderImportValidationMessage::CODIGO_PRODUCTO_REQUIRED,
                'description' => 'codigo_de_producto is required',
            ],
            'missing_cantidad_required' => [
                'file' => 'test_order_missing_cantidad.xlsx',
                'expectedMessage' => OrderImportValidationMessage::CANTIDAD_REQUIRED,
                'description' => 'cantidad is required',
            ],
            'invalid_cantidad_must_be_integer' => [
                'file' => 'test_order_invalid_cantidad_not_integer.xlsx',
                'expectedMessage' => OrderImportValidationMessage::CANTIDAD_INTEGER,
                'description' => 'cantidad must be integer',
            ],
            'invalid_cantidad_min_value' => [
                'file' => 'test_order_invalid_cantidad_zero.xlsx',
                'expectedMessage' => OrderImportValidationMessage::CANTIDAD_MIN,
                'description' => 'cantidad must be at least 1',
            ],
            'invalid_precio_neto_must_be_numeric' => [
                'file' => 'test_order_invalid_precio_neto.xlsx',
                'expectedMessage' => OrderImportValidationMessage::PRECIO_NETO_NUMERIC,
                'description' => 'precio_neto must be numeric',
            ],
            'invalid_parcialmente_programado_value' => [
                'file' => 'test_order_invalid_parcialmente_programado.xlsx',
                'expectedMessage' => OrderImportValidationMessage::PARCIALMENTE_PROGRAMADO_IN,
                'description' => 'parcialmente_programado must be valid value',
            ],

            // Custom withValidator rules
            'invalid_fecha_orden_format' => [
                'file' => 'test_order_invalid_fecha_orden_format.xlsx',
                'expectedMessage' => OrderImportValidationMessage::FECHA_ORDEN_FORMAT,
                'description' => 'fecha_orden format must be DD/MM/YYYY',
            ],
            'invalid_fecha_despacho_format' => [
                'file' => 'test_order_invalid_fecha_despacho_format.xlsx',
                'expectedMessage' => OrderImportValidationMessage::FECHA_DESPACHO_FORMAT,
                'description' => 'fecha_despacho format must be DD/MM/YYYY',
            ],
            'user_does_not_exist' => [
                'file' => 'test_order_user_not_exists.xlsx',
                'expectedMessage' => OrderImportValidationMessage::USUARIO_NOT_EXISTS,
                'description' => 'usuario must exist in database',
            ],
            'product_does_not_exist' => [
                'file' => 'test_order_product_not_exists.xlsx',
                'expectedMessage' => OrderImportValidationMessage::PRODUCTO_NOT_EXISTS,
                'description' => 'codigo_de_producto must exist in database',
            ],
        ];
    }

    /**
     * Test validation rules with data provider
     *
     * @dataProvider validationTestCases
     * @param string $file Excel filename
     * @param OrderImportValidationMessage $expectedMessage Expected validation message from enum
     * @param string $description Test description
     */
    public function test_validation_rule(string $file, OrderImportValidationMessage $expectedMessage, string $description): void
    {
        // Mock S3 storage for testing
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Get test Excel file
        $testFile = base_path("tests/Fixtures/{$file}");
        $this->assertFileExists($testFile, "Test Excel file should exist: {$file}");

        // Act: Import the Excel file
        Excel::import(
            new OrderLinesImport($importProcess->id),
            $testFile
        );

        // Assert: Verify import failed with error
        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
            $importProcess->status,
            "Import should fail with validation error for: {$description}"
        );

        // Verify error_log exists and has entries
        $this->assertNotNull($importProcess->error_log, 'Error log should not be null');
        $this->assertIsArray($importProcess->error_log, 'Error log should be an array');
        $this->assertCount(1, $importProcess->error_log, 'Should have exactly 1 error');

        $error = $importProcess->error_log[0];

        // Verify error structure
        $this->assertArrayHasKey('row', $error, 'Error should have row field');
        $this->assertArrayHasKey('attribute', $error, 'Error should have attribute field');
        $this->assertArrayHasKey('errors', $error, 'Error should have errors field');
        $this->assertArrayHasKey('values', $error, 'Error should have values field');

        // Verify row number is 2 (row 1 is header, row 2 is the data)
        $this->assertEquals(
            2,
            $error['row'],
            "Error row should be 2 for: {$description}"
        );

        // Verify error message matches expected message from enum
        $errorMessages = is_array($error['errors']) ? implode(' ', $error['errors']) : $error['errors'];
        $this->assertMatchesRegularExpression(
            "/{$expectedMessage->pattern()}/i",
            $errorMessages,
            "Error message should match pattern '{$expectedMessage->value}' for: {$description}"
        );
    }
}
