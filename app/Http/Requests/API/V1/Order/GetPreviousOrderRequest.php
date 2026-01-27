<?php

namespace App\Http\Requests\API\V1\Order;

use App\Http\Requests\API\V1\Menu\DelegateUserRequest;

class GetPreviousOrderRequest extends DelegateUserRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'date' => 'required|date_format:Y-m-d',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date' => $this->route('date'),
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'date.required' => 'The date field is required.',
            'date.date_format' => 'The date must be in the format yyyy-mm-dd.',
        ]);
    }
}