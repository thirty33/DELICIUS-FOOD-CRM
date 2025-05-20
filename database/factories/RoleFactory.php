<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Role>
 */
class RoleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Role::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(array_column(RoleName::cases(), 'value')),
        ];
    }

    /**
     * Configure the role as Admin.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function admin()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => RoleName::ADMIN->value,
            ];
        });
    }

    /**
     * Configure the role as Café.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function cafe()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => RoleName::CAFE->value,
            ];
        });
    }

    /**
     * Configure the role as Convenio.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function agreement()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => RoleName::AGREEMENT->value,
            ];
        });
    }
}