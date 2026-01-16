<?php

namespace App\Http\Requests\API\V1\Order;

use App\Http\Requests\API\V1\Menu\DelegateUserRequest;

class UpdateStatusRequest extends DelegateUserRequest
{
    public function rules(): array
    {
        // Merge parent rules with our specific rules
        return array_merge(parent::rules(), [
            'date' => $this->route('date') ? 'date_format:Y-m-d' : 'required|date_format:Y-m-d',
            'status' => 'required|string',
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
            'status.required' => 'The status field is required.',
            'status.string' => 'The status field must be a string.',
        ]);
    }

    public function authorize(): bool
    {
        // Execute parent authorization (delegate user validation)
        // This will throw HttpResponseException if validation fails
        parent::authorize();

        // Check if there is a menu that meets the conditions for the effective user
        return $this->authorizeMenuAccess('menuExistsForStatusUpdate');
    }
}
