<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder for OrderLinesImport Test Data
 *
 * Creates minimal data needed to test a single order import
 * Based on first order from Libro5.xlsx: 20251103510024
 */
class OrderLinesImportTestSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Price List
        $priceList = PriceList::create([
            'name' => 'Lista General Test',
            'description' => 'Lista de precios para testing',
            'min_price_order' => 0,
        ]);

        // 2. Create Company
        $company = Company::create([
            'name' => 'ALIMENTOS Y ACEITES SPA',
            'tax_id' => '76.505.808-2',
            'company_code' => '76.505.808-2',
            'fantasy_name' => 'ALIMENTOS Y ACEITES',
            'price_list_id' => $priceList->id,
            'email' => 'contacto@aliace.cl',
            'phone_number' => '912345678',
        ]);

        // 3. Create Branch
        $branch = Branch::create([
            'company_id' => $company->id,
            'fantasy_name' => 'CONVENIO ALIACE',
            'address' => 'Dirección de prueba',
            'shipping_address' => 'Dirección de despacho',
            'min_price_order' => 0,
        ]);

        // 4. Get existing Role and Permission (should exist in system)
        $role = Role::where('name', RoleName::ADMIN->value)->first();
        $permission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // If they don't exist, create them without guard_name
        if (!$role) {
            $role = Role::create(['name' => RoleName::ADMIN->value]);
        }

        if (!$permission) {
            $permission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);
        }

        // 5. Create User
        $user = User::create([
            'name' => 'Usuario Aliace',
            'email' => 'RECEPCION@ALIACE.CL',
            'nickname' => 'RECEPCION@ALIACE.CL',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'validate_subcategory_rules' => false,
            'active' => true,
        ]);

        if ($role) {
            $user->roles()->attach($role->id);
        }

        if ($permission) {
            $user->permissions()->attach($permission->id);
        }

        // 6. Create Categories
        $categories = [
            'MINI ENSALADAS DE ACOMPAÑAMIENTO',
            'ACOMPAÑAMIENTOS',
            'PLATOS VARIABLES PARA CALENTAR HORECA',
            'POSTRES'
        ];

        $categoryModels = [];
        foreach ($categories as $categoryName) {
            $categoryModels[$categoryName] = Category::create([
                'name' => $categoryName,
                'is_active' => true,
            ]);
        }

        // 7. Create Products
        $products = [
            [
                'code' => 'ACM00000043',
                'name' => 'ACM - MINI ENSALADA ACEITUNAS Y HUEVO DURO',
                'category' => 'MINI ENSALADAS DE ACOMPAÑAMIENTO',
                'price' => 400,
            ],
            [
                'code' => 'EXT00000001',
                'name' => 'EXT - AMASADO DELICIUS MINI',
                'category' => 'ACOMPAÑAMIENTOS',
                'price' => 100,
            ],
            [
                'code' => 'PCH00000003',
                'name' => 'PCH - HORECA ALBONDIGAS ATOMATADAS CON ARROZ PRIMAVERA',
                'category' => 'PLATOS VARIABLES PARA CALENTAR HORECA',
                'price' => 4600,
            ],
            [
                'code' => 'PTR00000005',
                'name' => 'PTR - FRUTA ESTACION 150 GR.',
                'category' => 'POSTRES',
                'price' => 850,
            ],
        ];

        foreach ($products as $productData) {
            $product = Product::create([
                'code' => $productData['code'],
                'name' => $productData['name'],
                'description' => $productData['name'],
                'category_id' => $categoryModels[$productData['category']]->id,
                'measure_unit' => 'UND',
                'weight' => 0,
                'active' => true,
                'allow_sales_without_stock' => true,
            ]);

            // Create price list line
            PriceListLine::create([
                'price_list_id' => $priceList->id,
                'product_id' => $product->id,
                'unit_price' => $productData['price'],
            ]);
        }

        $this->command->info('✅ Test data seeded successfully!');
        $this->command->info("   - Company: {$company->name}");
        $this->command->info("   - User: {$user->email}");
        $this->command->info("   - Products: " . count($products));
    }
}
