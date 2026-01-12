<?php

namespace App\Http\Requests\API\V1\WebRegistration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreWebRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'razon_social' => ['nullable', 'string', 'max:255'],
            'rut' => ['nullable', 'string', 'max:12'],
            'nombre_fantasia' => ['nullable', 'string', 'max:255'],
            'tipo_cliente' => ['nullable', 'string', 'max:50'],
            'giro' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'telefono' => ['required_without:email', 'nullable', 'string', 'max:20'],
            'email' => ['required_without:telefono', 'nullable', 'email', 'max:255'],
            'mensaje' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'email.required_without' => 'Debe proporcionar un email o un teléfono de contacto.',
            'email.email' => 'El campo email debe ser una dirección de correo válida.',
            'email.max' => 'El campo email no debe exceder los 255 caracteres.',
            'telefono.required_without' => 'Debe proporcionar un teléfono o un email de contacto.',
            'telefono.max' => 'El campo teléfono no debe exceder los 20 caracteres.',
            'mensaje.required' => 'El campo mensaje es obligatorio.',
            'mensaje.max' => 'El campo mensaje no debe exceder los 2000 caracteres.',
            'razon_social.max' => 'El campo razón social no debe exceder los 255 caracteres.',
            'rut.max' => 'El campo RUT no debe exceder los 12 caracteres.',
            'nombre_fantasia.max' => 'El campo nombre fantasía no debe exceder los 255 caracteres.',
            'tipo_cliente.max' => 'El campo tipo de cliente no debe exceder los 50 caracteres.',
            'giro.max' => 'El campo giro no debe exceder los 255 caracteres.',
            'direccion.max' => 'El campo dirección no debe exceder los 500 caracteres.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'error',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
