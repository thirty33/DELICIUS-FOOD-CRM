<?php

namespace Database\Seeders;

use App\Models\Branch;
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
        
        $this->call(CompaniesAndUsersSeeder::class);
        $this->call(CategoriesSeeder::class);
        $this->call(ProductsSeeder::class);
        $this->call(CategoryMenusSeeder::class);
        $this->call(PriceListLinesSeeder::class);

    }
}
