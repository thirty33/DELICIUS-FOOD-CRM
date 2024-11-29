<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Company;
use App\Models\Role;
use App\Models\Permission;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Verifica si existen antes de crearlos
        Role::firstOrCreate(['id' => Role::ADMIN], ['name' => 'Admin']);
        Role::firstOrCreate(['id' => Role::CAFE], ['name' => 'Café']);
        Role::firstOrCreate(['id' => Role::AGREEMENT], ['name' => 'Convenio']);

        Permission::firstOrCreate(['id' => Permission::CONSOLIDATED], ['name' => 'Consolidado']);
        Permission::firstOrCreate(['id' => Permission::INDIVIDUAL_AGREEMENT], ['name' => 'Individual']);

        $company = Company::firstOrCreate([
            'email' => 'contact@example.com',
        ], [
            'name' => 'Delicius Food',
            'address' => '123 Main St',
            'phone_number' => '555-1234',
            'website' => 'https://example.com',
            'registration_number' => 'REG7890',
            'description' => 'This is an example company.',
            'active' => true,
            'fantasy_name' => 'Delicius Food'
        ]);


        $admin = User::firstOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'company_id' => $company->id,
            'password' => static::$password ??= Hash::make('Pssword123..$'),
        ]);

        if (!$admin->roles()->where('role_id', Role::ADMIN)->exists()) {
            $admin->roles()->attach(Role::ADMIN);
        }

        $cafe = User::firstOrCreate([
            'email' => 'cafe@example.com',
        ], [
            'name' => 'Cafe User',
            'company_id' => $company->id,
            'password' => static::$password ??= Hash::make('password'),
        ]);

        if (!$cafe->roles()->where('role_id', Role::CAFE)->exists()) {
            $cafe->roles()->attach(Role::CAFE);
        }

        $agreement_consolidated = User::firstOrCreate([
            'email' => 'agreement_consolidated@example.com',
        ], [
            'name' => 'Convenio Consolidado User',
            'company_id' => $company->id,
            'password' => static::$password ??= Hash::make('password'),
        ]);

        if (!$agreement_consolidated->roles()->where('role_id', Role::AGREEMENT)->exists()) {
            $agreement_consolidated->roles()->attach(Role::AGREEMENT);
        }

        if (!$agreement_consolidated->permissions()->where('permission_id', Permission::CONSOLIDATED)->exists()) {
            $agreement_consolidated->permissions()->attach(Permission::CONSOLIDATED);
        }

        $agreement_individual = User::firstOrCreate([
            'email' => 'agreement_individual@example.com',
        ], [
            'name' => 'Convenio Individual User',
            'company_id' => $company->id,
            'password' => static::$password ??= Hash::make('password'),
        ]);

        if (!$agreement_individual->roles()->where('role_id', Role::AGREEMENT)->exists()) {
            $agreement_individual->roles()->attach(Role::AGREEMENT);
        }

        if (!$agreement_individual->permissions()->where('permission_id', Permission::INDIVIDUAL_AGREEMENT)->exists()) {
            $agreement_individual->permissions()->attach(Permission::INDIVIDUAL_AGREEMENT);
        }

        $this->call(CategoriesSeeder::class);
        $this->call(ProductsSeeder::class);

    }
}
