<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'address' => fake()->address(),
            'email' => fake()->companyEmail(),
            'phone_number' => fake()->phoneNumber(),
            'website' => fake()->url(),
            'registration_number' => 'REG' . fake()->numerify('####'),
            'description' => fake()->sentence(),
            'logo' => null,
            'active' => true,
            'tax_id' => fake()->numerify('##.###.###-#'),
            'business_activity' => 'NVE',
            'acronym' => fake()->lexify('???'),
            'shipping_address' => fake()->address(),
            'district' => fake()->city(),
            'state_region' => fake()->state(),
            'postal_box' => fake()->postcode(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'zip_code' => fake()->postcode(),
            'fax' => null,
            'company_name' => fake()->company(),
            'contact_name' => fake()->firstName(),
            'contact_last_name' => fake()->lastName(),
            'contact_phone_number' => fake()->phoneNumber(),
            'fantasy_name' => fake()->companySuffix() . ' ' . fake()->company(),
            'price_list_id' => null,
            'company_code' => fake()->numerify('##.###.###-#'),
            'payment_condition' => 'CONTADO',
            'exclude_from_consolidated_report' => false,
        ];
    }
}
