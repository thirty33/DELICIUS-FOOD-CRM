<?php

namespace App\Http\Requests\API\V1\User;

use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
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
        return [
            // Pagination
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],

            // User repository filters
            'company_search' => ['nullable', 'string', 'max:255'],
            'branch_search' => ['nullable', 'string', 'max:255'],
            'user_search' => ['nullable', 'string', 'max:255'],
            'user_role' => ['nullable', 'string', Rule::in(RoleName::getValues())],
            'user_permission' => ['nullable', 'string', Rule::in(PermissionName::getValues())],

            // Menu repository filters
            'start_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'order_status' => ['nullable', 'string', Rule::in(OrderStatus::getValues())],
        ];
    }

    /**
     * Get user repository search filters from request.
     */
    public function getUserSearchFilters(): array
    {
        return array_filter([
            'company_search' => $this->input('company_search'),
            'branch_search' => $this->input('branch_search'),
            'user_search' => $this->input('user_search'),
            'user_role' => $this->input('user_role'),
            'user_permission' => $this->input('user_permission'),
        ], fn($value) => !is_null($value) && $value !== '');
    }

    /**
     * Get menu repository search filters from request.
     */
    public function getMenuSearchFilters(): array
    {
        return array_filter([
            'start_date' => $this->input('start_date'),
            'end_date' => $this->input('end_date'),
            'order_status' => $this->input('order_status'),
        ], fn($value) => !is_null($value) && $value !== '');
    }
}