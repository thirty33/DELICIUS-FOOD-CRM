<?php

namespace App\Http\Requests\API\V1\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Services\API\V1\ApiResponseService;

class GetPreviousOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date' => $this->route('date'),
        ]);
    }

    public function messages(): array
    {
        return [
            'date.required' => 'The date field is required.',
            'date.date_format' => 'The date must be in the format yyyy-mm-dd.',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponseService::unprocessableEntity('error', $validator->errors()->toArray())
        );
    }
}