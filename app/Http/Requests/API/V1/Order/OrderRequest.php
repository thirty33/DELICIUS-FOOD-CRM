<?php

namespace App\Http\Requests\API\V1\Order;

use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
{
    public function rules()
    {
        return [
            'date' => $this->route('date') ? 'date_format:Y-m-d' : 'required|date_format:Y-m-d'
        ];
    }
    
    protected function prepareForValidation()
    {
        $this->merge([
            'date' => $this->route('date')
        ]);
    }

    public function messages()
    {
        return [
            'date.required' => 'The date field is required.',
            'date.date_format' => 'The date must be in the format yyyy-mm-dd.',
        ];
    }

    public function authorize()
    {
        return true;
    }
}
