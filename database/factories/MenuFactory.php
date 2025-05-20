<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\Role;
use App\Models\Permission;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Menu>
 */
class MenuFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Menu::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $publicationDate = Carbon::now()->addDays(rand(1, 30));
        
        return [
            'title' => $this->faker->unique()->sentence(3),
            'description' => $this->faker->paragraph(2, true),
            'publication_date' => $publicationDate->format('Y-m-d'),
            'max_order_date' => $publicationDate->copy()->subDays(rand(1, 3))->format('Y-m-d H:i:s'),
            'role_id' => Role::inRandomOrder()->first()->id,
            'permissions_id' => function (array $attributes) {
                // Solo asignamos permiso si es un rol de Café o Convenio
                $role = Role::find($attributes['role_id']);
                if ($role && in_array($role->name, ['Café', 'Convenio'])) {
                    return Permission::inRandomOrder()->first()->id;
                }
                return null;
            },
            'active' => $this->faker->boolean(80), // 80% serán activos
        ];
    }

    /**
     * Indicate that the menu is active.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function active()
    {
        return $this->state(function (array $attributes) {
            return [
                'active' => true,
            ];
        });
    }

    /**
     * Indicate that the menu is inactive.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function inactive()
    {
        return $this->state(function (array $attributes) {
            return [
                'active' => false,
            ];
        });
    }

    /**
     * Set a specific role for the menu.
     *
     * @param string $roleName
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forRole(string $roleName)
    {
        return $this->state(function (array $attributes) use ($roleName) {
            $role = Role::where('name', $roleName)->first();
            
            return [
                'role_id' => $role ? $role->id : Role::inRandomOrder()->first()->id,
            ];
        });
    }

    /**
     * Set a specific permission for the menu.
     *
     * @param string $permissionName
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withPermission(string $permissionName)
    {
        return $this->state(function (array $attributes) use ($permissionName) {
            $permission = Permission::where('name', $permissionName)->first();
            
            return [
                'permissions_id' => $permission ? $permission->id : Permission::inRandomOrder()->first()->id,
            ];
        });
    }

    /**
     * Set the menu to be published today.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function publishToday()
    {
        return $this->state(function (array $attributes) {
            $today = Carbon::today();
            
            return [
                'publication_date' => $today->format('Y-m-d'),
                'max_order_date' => $today->copy()->subDay()->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * Set the menu to be published in the future.
     *
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function publishInFuture(int $days = 7)
    {
        return $this->state(function (array $attributes) use ($days) {
            $futureDate = Carbon::today()->addDays($days);
            
            return [
                'publication_date' => $futureDate->format('Y-m-d'),
                'max_order_date' => $futureDate->copy()->subDays(3)->format('Y-m-d H:i:s'),
            ];
        });
    }
}