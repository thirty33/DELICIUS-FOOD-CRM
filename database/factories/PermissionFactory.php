<?php

namespace Database\Factories;

use App\Enums\PermissionName;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Permission::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(array_column(PermissionName::cases(), 'value')),
        ];
    }

    /**
     * Configure the permission as Consolidado.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function consolidado()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => PermissionName::CONSOLIDADO->value,
            ];
        });
    }

    /**
     * Configure the permission as Individual.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function individual()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => PermissionName::INDIVIDUAL->value,
            ];
        });
    }
}