<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'address' => fake()->address(),
            'shipping_address' => fake()->address(),
            'contact_name' => fake()->firstName(),
            'contact_last_name' => fake()->lastName(),
            'contact_phone_number' => fake()->phoneNumber(),
            'branch_code' => fake()->unique()->numerify('BRANCH-####'),
            'fantasy_name' => fake()->companySuffix() . ' ' . fake()->city(),
            'min_price_order' => fake()->numberBetween(10000, 50000),
        ];
    }
}
