<?php

namespace App\Http\Requests\API\V1\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Services\API\V1\ApiResponseService;

class GetSubordinateUsersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        
        if (!$user) {
            throw new HttpResponseException(
                ApiResponseService::unauthorized('User not authenticated')
            );
        }
        
        if (!$user->master_user) {
            throw new HttpResponseException(
                ApiResponseService::forbidden('You do not have permission to access this feature. Master user status is required.')
            );
        }
        
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}