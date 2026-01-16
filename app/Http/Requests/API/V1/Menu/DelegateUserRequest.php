<?php

namespace App\Http\Requests\API\V1\Menu;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Services\API\V1\ApiResponseService;
use App\Models\User;
use App\Repositories\UserDelegationRepository;
use App\Classes\Menus\MenuHelper;

class DelegateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $authenticatedUser = $this->user();
        
        if (!$authenticatedUser) {
            throw new HttpResponseException(
                ApiResponseService::unprocessableEntity('error', [
                    'message' => ['User not authenticated']
                ])
            );
        }
        
        // If user is master_user and delegate_user is not present, throw error
        if ($authenticatedUser->master_user && !$this->has('delegate_user')) {
            throw new HttpResponseException(
                ApiResponseService::unprocessableEntity('error', [
                    'delegate_user' => ['Master users must specify a delegate_user parameter']
                ])
            );
        }
        
        // If delegate_user is present, check if authenticated user is master_user
        if ($this->has('delegate_user') && !$authenticatedUser->master_user) {
            throw new HttpResponseException(
                ApiResponseService::unprocessableEntity('error', [
                    'delegate_user' => ['You must be a master user to delegate requests']
                ])
            );
        }
        
        // Check if both users belong to the same company when delegate_user is present
        if ($this->has('delegate_user')) {
            $delegateNickname = $this->input('delegate_user');
            $delegateUser = User::where('nickname', $delegateNickname)->first();

            if ($delegateUser) {
                // Super master users can delegate to any company
                // Regular master users can only delegate within their company
                if (!$authenticatedUser->super_master_user &&
                    $authenticatedUser->company_id !== $delegateUser->company_id) {
                    throw new HttpResponseException(
                        ApiResponseService::unprocessableEntity('error', [
                            'delegate_user' => ['You can only delegate to users within your company']
                        ])
                    );
                }
            }
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
        $user = $this->user();
        
        // If user is master_user, delegate_user is required
        if ($user && $user->master_user) {
            return [
                'delegate_user' => [
                    'required',
                    'string',
                    'exists:users,nickname'
                ]
            ];
        }
        
        // For non-master users, delegate_user is optional
        return [
            'delegate_user' => [
                'sometimes',
                'nullable',
                'string',
                'exists:users,nickname'
            ]
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'delegate_user.required' => 'Master users must specify a delegate_user parameter',
            'delegate_user.exists' => 'The specified delegate user does not exist',
            'delegate_user.string' => 'The delegate user must be a valid nickname',
        ];
    }
    
    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(
            ApiResponseService::unprocessableEntity('error', $validator->errors()->toArray())
        );
    }

    /**
     * Authorize menu access for the effective user using the specified MenuHelper method.
     *
     * @param string $menuHelperMethod The MenuHelper method name to call
     * @return bool
     */
    protected function authorizeMenuAccess(string $menuHelperMethod): bool
    {
        $date = $this->input('date');

        $userDelegationRepository = app(UserDelegationRepository::class);
        $effectiveUser = $userDelegationRepository->getEffectiveUser($this);

        $firstRole = $effectiveUser->roles->first();
        $roleId = $firstRole ? $firstRole->id : null;

        $firstPermission = $effectiveUser->permissions->first();
        $permissionId = $firstPermission ? $firstPermission->id : null;

        $companyId = $effectiveUser->company_id;

        $menuExists = MenuHelper::$menuHelperMethod($date, $roleId, $permissionId, $companyId);

        return $menuExists;
    }
}