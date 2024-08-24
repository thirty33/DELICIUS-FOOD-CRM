<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Company;
use App\Models\Role;
use App\Models\Permission;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        Role::create([
            'id' => Role::ADMIN,
            'name' => 'Admin',
        ]);

        Role::create([
            'id' => Role::CAFE,
            'name' => 'Café',
        ]);

        Role::create([
            'id' => Role::AGREEMENT,
            'name' => 'Convenio',
        ]);

        Permission::create([
            'id' => Permission::CONSOLIDATED,
            'name' => 'Consolidado'
        ]);

        Permission::create([
            'id' => Permission::INDIVIDUAL_AGREEMENT,
            'name' => 'Individual'
        ]);

        $company = Company::create([
            'name' => 'Delicius Food',
            'address' => '123 Main St',
            'email' => 'contact@example.com',
            'phone_number' => '555-1234',
            'website' => 'https://example.com',
            'registration_number' => 'REG7890',
            'description' => 'This is an example company.',
            // 'logo' => 'path/to/logo.png',
            'active' => true
        ]);

        $admin = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'company_id' => $company->id,
        ]);

        $admin->roles()->attach(Role::ADMIN);

        $cafe = User::factory()->create([
            'name' => 'Cafe User',
            'email' => 'cafe@example.com',
            'company_id' => $company->id,
        ]);

        $cafe->roles()->attach(Role::CAFE);

        $agreement_consolidated = User::factory()->create([
            'name' => 'Convenio Consolidado User',
            'email' => 'agreement_consolidated@example.com',
            'company_id' => $company->id,
        ]);

        $agreement_consolidated->roles()->attach(Role::AGREEMENT);
        $agreement_consolidated->permissions()->attach(Permission::CONSOLIDATED);

        $agreement_individual = User::factory()->create([
            'name' => 'Convenio Individual User',
            'email' => 'agreement_individual@example.com',
            'company_id' => $company->id,
        ]);

        $agreement_individual->roles()->attach(Role::AGREEMENT);
        $agreement_individual->permissions()->attach(Permission::INDIVIDUAL_AGREEMENT);
    }
}
