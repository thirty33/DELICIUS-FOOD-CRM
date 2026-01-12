<?php

namespace Tests\Feature\API\V1;

use App\Models\WebRegistrationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/web-registration';
    private string $validApiKey = 'test-api-key-for-testing';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.public_api.key' => $this->validApiKey]);
    }

    // =========================================================================
    // API KEY AUTHENTICATION TESTS
    // =========================================================================

    public function test_returns_401_when_api_key_is_missing(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'error',
                'errors' => [
                    'api_key' => ['Invalid or missing API key.'],
                ],
            ]);
    }

    public function test_returns_401_when_api_key_is_incorrect(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => 'wrong-api-key',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'error',
                'errors' => [
                    'api_key' => ['Invalid or missing API key.'],
                ],
            ]);
    }

    public function test_returns_201_when_api_key_is_correct(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Solicitud de registro creada exitosamente',
            ]);

        $this->assertDatabaseHas('web_registration_requests', [
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ]);
    }

    // =========================================================================
    // VALIDATION TESTS - REQUIRED FIELDS
    // =========================================================================

    public function test_validation_fails_when_mensaje_is_missing(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => 'test@example.com',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mensaje'])
            ->assertJson([
                'message' => 'error',
                'errors' => [
                    'mensaje' => ['El campo mensaje es obligatorio.'],
                ],
            ]);
    }

    public function test_validation_fails_when_email_and_telefono_are_missing(): void
    {
        $response = $this->postJson($this->endpoint, [
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'telefono']);
    }

    // =========================================================================
    // VALIDATION TESTS - EMAIL AND TELEFONO EXCLUSIVITY
    // =========================================================================

    public function test_succeeds_with_only_email_and_mensaje(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => 'solo-email@example.com',
            'mensaje' => 'Message with only email',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('web_registration_requests', [
            'email' => 'solo-email@example.com',
            'mensaje' => 'Message with only email',
            'telefono' => null,
        ]);
    }

    public function test_succeeds_with_only_telefono_and_mensaje(): void
    {
        $response = $this->postJson($this->endpoint, [
            'telefono' => '+56912345678',
            'mensaje' => 'Message with only phone',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('web_registration_requests', [
            'telefono' => '+56912345678',
            'mensaje' => 'Message with only phone',
            'email' => null,
        ]);
    }

    public function test_succeeds_with_both_email_and_telefono(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => 'both@example.com',
            'telefono' => '+56912345678',
            'mensaje' => 'Message with both',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('web_registration_requests', [
            'email' => 'both@example.com',
            'telefono' => '+56912345678',
            'mensaje' => 'Message with both',
        ]);
    }

    // =========================================================================
    // VALIDATION TESTS - EMAIL FORMAT
    // =========================================================================

    public function test_validation_fails_when_email_format_is_invalid(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => 'invalid-email',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJson([
                'errors' => [
                    'email' => ['El campo email debe ser una dirección de correo válida.'],
                ],
            ]);
    }

    // =========================================================================
    // VALIDATION TESTS - MAX LENGTH
    // =========================================================================

    public function test_validation_fails_when_razon_social_exceeds_max_length(): void
    {
        $response = $this->postJson($this->endpoint, [
            'razon_social' => str_repeat('a', 256),
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['razon_social']);
    }

    public function test_validation_fails_when_rut_exceeds_max_length(): void
    {
        $response = $this->postJson($this->endpoint, [
            'rut' => str_repeat('1', 13),
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rut']);
    }

    public function test_validation_fails_when_nombre_fantasia_exceeds_max_length(): void
    {
        $response = $this->postJson($this->endpoint, [
            'nombre_fantasia' => str_repeat('a', 256),
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nombre_fantasia']);
    }

    public function test_validation_fails_when_tipo_cliente_exceeds_max_length(): void
    {
        $response = $this->postJson($this->endpoint, [
            'tipo_cliente' => str_repeat('a', 51),
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tipo_cliente']);
    }

    public function test_validation_fails_when_giro_exceeds_max_length(): void
    {
        $response = $this->postJson($this->endpoint, [
            'giro' => str_repeat('a', 256),
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['giro']);
    }

    public function test_validation_fails_when_direccion_exceeds_max_length(): void
    {
        $response = $this->postJson($this->endpoint, [
            'direccion' => str_repeat('a', 501),
            'email' => 'test@example.com',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['direccion']);
    }

    public function test_validation_fails_when_telefono_exceeds_max_length(): void
    {
        $response = $this->postJson($this->endpoint, [
            'telefono' => str_repeat('1', 21),
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['telefono']);
    }

    public function test_validation_fails_when_email_exceeds_max_length(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => str_repeat('a', 250) . '@test.com',
            'mensaje' => 'Test message',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_validation_fails_when_mensaje_exceeds_max_length(): void
    {
        $response = $this->postJson($this->endpoint, [
            'email' => 'test@example.com',
            'mensaje' => str_repeat('a', 2001),
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mensaje']);
    }

    // =========================================================================
    // SUCCESSFUL REQUEST WITH ALL FIELDS
    // =========================================================================

    public function test_succeeds_with_all_fields(): void
    {
        $response = $this->postJson($this->endpoint, [
            'razon_social' => 'Empresa Test S.A.',
            'rut' => '12.345.678-9',
            'nombre_fantasia' => 'Test Company',
            'tipo_cliente' => 'Restaurante',
            'giro' => 'Venta de alimentos',
            'direccion' => 'Av. Principal 123, Santiago',
            'telefono' => '+56912345678',
            'email' => 'contacto@empresa.cl',
            'mensaje' => 'Estamos interesados en sus servicios de catering.',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('web_registration_requests', [
            'razon_social' => 'Empresa Test S.A.',
            'rut' => '12.345.678-9',
            'nombre_fantasia' => 'Test Company',
            'tipo_cliente' => 'Restaurante',
            'giro' => 'Venta de alimentos',
            'direccion' => 'Av. Principal 123, Santiago',
            'telefono' => '+56912345678',
            'email' => 'contacto@empresa.cl',
            'mensaje' => 'Estamos interesados en sus servicios de catering.',
            'status' => 'pending',
        ]);
    }

    // =========================================================================
    // DATABASE RECORD VERIFICATION
    // =========================================================================

    public function test_creates_record_with_pending_status(): void
    {
        $this->postJson($this->endpoint, [
            'email' => 'status-test@example.com',
            'mensaje' => 'Testing status',
        ], [
            'X-API-KEY' => $this->validApiKey,
        ]);

        $record = WebRegistrationRequest::where('email', 'status-test@example.com')->first();

        $this->assertNotNull($record);
        $this->assertEquals('pending', $record->status);
        $this->assertNull($record->admin_notes);
    }
}
