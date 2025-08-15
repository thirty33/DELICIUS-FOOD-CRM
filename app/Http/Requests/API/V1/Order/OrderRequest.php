<?php

namespace App\Http\Requests\API\V1\Order;

use App\Http\Requests\API\V1\Menu\DelegateUserRequest;

class OrderRequest extends DelegateUserRequest
{
    public function rules(): array
    {
        // Merge parent rules with our specific rules
        return array_merge(parent::rules(), [
            'date' => $this->route('date') ? 'date_format:Y-m-d' : 'required|date_format:Y-m-d'
        ]);
    }
    
    protected function prepareForValidation()
    {
        $this->merge([
            'date' => $this->route('date')
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'date.required' => 'The date field is required.',
            'date.date_format' => 'The date must be in the format yyyy-mm-dd.',
        ]);
    }

    public function authorize(): bool
    {
        // Execute parent authorization (delegate user validation)
        // This will throw HttpResponseException if validation fails
        parent::authorize();
        
        return true;
    }
}
