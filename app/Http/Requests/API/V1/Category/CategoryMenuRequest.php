<?php

namespace App\Http\Requests\API\V1\Category;

use App\Enums\RoleName;
use App\Models\Menu;
use App\Http\Requests\API\V1\Menu\DelegateUserRequest;

class CategoryMenuRequest extends DelegateUserRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Execute parent authorization (delegate user validation)
        // This will throw HttpResponseException if validation fails
        parent::authorize();
        
        $user = auth()->user();
        $menuId = $this->route('menu')->id;

        if ($user->hasRole(RoleName::ADMIN->value)) {
            return true;
        }

        if ($user->hasRole(RoleName::CAFE->value)) {
            $menu = Menu::find($menuId);
            return $menu->rol->name === RoleName::CAFE->value;
        }

        if ($user->hasRole(RoleName::AGREEMENT->value)) {
            $menu = Menu::find($menuId);
            return $menu->permission && $user->hasPermission($menu->permission->name);
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
            // 'menuId' => 'required|integer|min:1'
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // 'menuId.required' => 'El ID del menú es obligatorio.',
            // 'menuId.integer' => 'El ID del menú debe ser un número entero.',
            // 'menuId.min' => 'El ID del menú debe ser un número positivo mayor a cero.'
        ];
    }
}
