<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use App\Models\Company;
use App\Models\Role;
use App\Models\Permission;
use App\Models\PriceList;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class CompaniesAndUsersSeeder extends Seeder
{
    /**
     * The default password for users.
     */
    protected static $password;

    private function addRole(User $user, string $role) {
        if (!$user->roles()->where('role_id', $role)->exists()) {
            $user->roles()->attach($role);
        }
    }

    private function addPermission(User $user, string $permission) {
        if (!$user->permissions()->where('permission_id', $permission)->exists()) {
            $user->permissions()->attach($permission);
        }
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        // price lists
        $price_list_one = PriceList::firstOrCreate([
            'name' => 'Price List 1',
        ],[
            'description' => 'price list from company 1',
            'min_price_order' => null
        ]);

        $price_list_two = PriceList::firstOrCreate([
            'name' => 'Price List 2',
        ],[
            'description' => 'price list from company 2',
            'min_price_order' => null
        ]);

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
            'fantasy_name' => 'Delicius Food',
            'price_list_id' => $price_list_one->id
        ]);

        $branch = Branch::firstOrCreate([
            'company_id' => $company->id,
            'address' => 'chile',
            'shipping_address' => 'chile',
            'contact_name' => 'Yonathan',
            'contact_last_name' => 'Martinez',
            'contact_phone_number' => '+5713213213',
            'branch_code' => 'CODE-0231',
            'fantasy_name' => 'Delicius Food sucursal',
            'min_price_order' => 2132100
        ]);
        
        $admin = User::firstOrCreate([
            'email' => 'yonathan.martinez@deliciusfood.cl',
        ], [
            'name' => 'Yonathan Martinez',
            'company_id' => $company->id,
            'password' => static::$password ??= Hash::make('Pssword123..$'),
            'branch_id' => $branch->id
        ]);

        if (!$admin->roles()->where('role_id', Role::ADMIN)->exists()) {
            $admin->roles()->attach(Role::ADMIN);
        }

        $cafe = User::firstOrCreate([
            'email' => 'cafe@example.com',
        ], [
            'name' => 'Cafe User',
            'company_id' => $company->id,
            'password' => static::$password ??= Hash::make('Pssword123..$'),
            'branch_id' => $branch->id
        ]);

        if (!$cafe->roles()->where('role_id', Role::CAFE)->exists()) {
            $cafe->roles()->attach(Role::CAFE);
        }

        if (!$cafe->permissions()->where('permission_id', Permission::CONSOLIDATED)->exists()) {
            !$cafe->permissions()->attach(Permission::CONSOLIDATED);
        }

        $agreement_consolidated = User::firstOrCreate([
            'email' => 'agreement_consolidated@example.com',
        ], [
            'name' => 'Convenio Consolidado User',
            'company_id' => $company->id,
            'password' => static::$password ??= Hash::make('Pssword123..$'),
            'branch_id' => $branch->id
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
            'password' => static::$password ??= Hash::make('Pssword123..$'),
            'branch_id' => $branch->id
        ]);

        if (!$agreement_individual->roles()->where('role_id', Role::AGREEMENT)->exists()) {
            $agreement_individual->roles()->attach(Role::AGREEMENT);
        }

        if (!$agreement_individual->permissions()->where('permission_id', Permission::INDIVIDUAL_AGREEMENT)->exists()) {
            $agreement_individual->permissions()->attach(Permission::INDIVIDUAL_AGREEMENT);
        }
        
        //companies
        $company2 = Company::firstOrCreate([
            'email' => 'joelsuarez.1101@gmail.com',
        ], [
            'name' => 'JOELDEV',
            'address' => 'Merida',
            'phone_number' => '321321321',
            'website' => 'test',
            'registration_number' => 'REG-DE3798BA',
            'description' => 'test',
            'active' => true,
            'fantasy_name' => 'JOELDEV',
            'tax_id' => '312321',
            'business_activity' => 'Desarrollo de software',
            'acronym' => 'JOELDEV',
            'shipping_address' => 'Merida',
            'state_region' => 'merida',
            'city' => 'merida',
            'country' => 'venezuela',
            'contact_name' => 'test',
            'contact_last_name' => 'test',
            'contact_phone_number' => '3123123',
            'price_list_id' => 2,
            'company_code' => 'JOELDEV',
            'payment_condition' => 'test',
            'price_list_id' => $price_list_two->id
        ]); 
        
        //branches
        $branch2 = Branch::firstOrCreate([
            'company_id' => $company2->id,
            'address' => 'joeldevsuc01',
            'shipping_address' => 'joeldevsuc01',
            'contact_name' => 'joeldevsuc01',
            'contact_last_name' => 'joeldevsuc01',
            'contact_phone_number' => '3123221',
            'branch_code' => 'joeldevsuc01',
            'fantasy_name' => 'joeldevsuc01',
            'min_price_order' => 32313100
        ]);

        //users
        $agreement_individual_company_2 = User::firstOrCreate([
            'email' => 'agreement_individual_joeldev@example.com',
        ], [
            'name' => 'Convenio Individual User, Company 2',
            'company_id' => $company2->id,
            'password' => static::$password ??= Hash::make('Pssword123..$'),
            'branch_id' => $branch2->id
        ]);

        $this->addRole($agreement_individual_company_2, ROLE::AGREEMENT);
        $this->addPermission($agreement_individual_company_2, Permission::INDIVIDUAL_AGREEMENT);

        $agreement_consolidated_company_2 = User::firstOrCreate([
            'email' => 'agreement_consolidated_joeldev@example.com',
        ], [
            'name' => 'Convenio Individual User, Company 2',
            'company_id' => $company2->id,
            'password' => static::$password ??= Hash::make('Pssword123..$'),
            'branch_id' => $branch2->id
        ]);

        $this->addRole($agreement_consolidated_company_2, ROLE::AGREEMENT);
        $this->addPermission($agreement_consolidated_company_2, Permission::CONSOLIDATED);
    
    }
}
