<?php

namespace Tests\Unit;

use App\Casts\E164PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class E164PhoneNumberCastTest extends TestCase
{
    private E164PhoneNumber $cast;

    private Model $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cast = new E164PhoneNumber();
        $this->model = new class extends Model {};
    }

    // --- Chile (56) ---

    public function test_chilean_number_with_spaces(): void
    {
        $result = $this->cast->get($this->model, 'phone', '569 6353 1200', []);

        $this->assertEquals('56963531200', $result);
    }

    public function test_chilean_number_with_different_spacing(): void
    {
        $result = $this->cast->get($this->model, 'phone', '56 9 7180 1040', []);

        $this->assertEquals('56971801040', $result);
    }

    public function test_chilean_number_no_spaces(): void
    {
        $result = $this->cast->get($this->model, 'phone', '56977082566', []);

        $this->assertEquals('56977082566', $result);
    }

    public function test_chilean_number_with_plus_prefix(): void
    {
        $result = $this->cast->get($this->model, 'phone', '+56 9 6353 1200', []);

        $this->assertEquals('56963531200', $result);
    }

    // --- Local number without country code ---

    public function test_local_number_gets_default_country_code_prepended(): void
    {
        $result = $this->cast->get($this->model, 'phone', '963531200', []);

        $this->assertEquals('56963531200', $result);
    }

    // --- Other country codes ---

    public function test_colombian_number_keeps_country_code(): void
    {
        $result = $this->cast->get($this->model, 'phone', '57 310 555 1234', []);

        $this->assertEquals('573105551234', $result);
    }

    public function test_argentinian_number_keeps_country_code(): void
    {
        $result = $this->cast->get($this->model, 'phone', '54 11 5555 1234', []);

        $this->assertEquals('541155551234', $result);
    }

    public function test_bolivian_3_digit_code_keeps_country_code(): void
    {
        $result = $this->cast->get($this->model, 'phone', '591 7123 4567', []);

        $this->assertEquals('59171234567', $result);
    }

    public function test_usa_number_keeps_country_code(): void
    {
        $result = $this->cast->get($this->model, 'phone', '1 555 123 4567', []);

        $this->assertEquals('15551234567', $result);
    }

    public function test_spanish_number_keeps_country_code(): void
    {
        $result = $this->cast->get($this->model, 'phone', '34 612 345 678', []);

        $this->assertEquals('34612345678', $result);
    }

    // --- Null / empty / invalid ---

    public function test_null_value_returns_null(): void
    {
        $result = $this->cast->get($this->model, 'phone', null, []);

        $this->assertNull($result);
    }

    public function test_empty_string_returns_null(): void
    {
        $result = $this->cast->get($this->model, 'phone', '', []);

        $this->assertNull($result);
    }

    public function test_sin_informacion_returns_null(): void
    {
        $result = $this->cast->get($this->model, 'phone', 'SIN INFORMACION', []);

        $this->assertNull($result);
    }

    public function test_non_numeric_string_returns_null(): void
    {
        $result = $this->cast->get($this->model, 'phone', 'no tiene', []);

        $this->assertNull($result);
    }

    // --- Set (passthrough) ---

    public function test_set_returns_value_as_is(): void
    {
        $result = $this->cast->set($this->model, 'phone', '569 6353 1200', []);

        $this->assertEquals('569 6353 1200', $result);
    }

    public function test_set_null_returns_null(): void
    {
        $result = $this->cast->set($this->model, 'phone', null, []);

        $this->assertNull($result);
    }

    // --- Edge cases ---

    public function test_number_with_dashes(): void
    {
        $result = $this->cast->get($this->model, 'phone', '56-9-6353-1200', []);

        $this->assertEquals('56963531200', $result);
    }

    public function test_number_with_parentheses(): void
    {
        $result = $this->cast->get($this->model, 'phone', '+56 (9) 6353 1200', []);

        $this->assertEquals('56963531200', $result);
    }

    public function test_number_with_dots(): void
    {
        $result = $this->cast->get($this->model, 'phone', '56.9.6353.1200', []);

        $this->assertEquals('56963531200', $result);
    }
}