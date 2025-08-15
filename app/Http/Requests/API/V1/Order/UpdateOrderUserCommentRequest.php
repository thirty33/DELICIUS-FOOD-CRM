<?php

namespace App\Http\Requests\API\V1\Order;

use App\Http\Requests\API\V1\Menu\DelegateUserRequest;

class UpdateOrderUserCommentRequest extends DelegateUserRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Execute parent authorization (delegate user validation)
        // This will throw HttpResponseException if validation fails
        parent::authorize();
        
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Merge parent rules with our specific rules
        return array_merge(parent::rules(), [
            'order_id' => 'sometimes|integer|exists:orders,id',
            'user_comment' => 'required|string|max:240'
        ]);
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        // If the order_id is not provided in the request parameters,
        // use the route parameter 'id' instead
        if (!$this->has('order_id') && $this->route('id')) {
            $this->merge([
                'order_id' => $this->route('id')
            ]);
        }
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'user_comment.required' => 'The user comment field is required.',
            'user_comment.string' => 'The user comment field must be a string.',
            'user_comment.max' => 'The user comment field may not be greater than 240 characters.',
            'order_id.integer' => 'The order ID must be an integer.',
            'order_id.exists' => 'The selected order does not exist.',
        ]);
    }
}