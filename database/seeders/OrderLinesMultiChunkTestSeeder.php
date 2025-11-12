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
 * Seeder for Multi-Chunk OrderLinesImport Test
 *
 * Creates test data for validating that an order split across multiple chunks
 * (chunk size = 100) is correctly merged into a single order.
 *
 * Test Scenario:
 * - Order: 20251103999999 (test order number)
 * - Total Lines: 150 (will be processed in 2 chunks: 100 + 50)
 * - Expected Result: 1 Order with 150 OrderLines
 */
class OrderLinesMultiChunkTestSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Price List
        $priceList = PriceList::create([
            'name' => 'Lista Multi-Chunk Test',
            'description' => 'Lista de precios para testing multi-chunk',
            'min_price_order' => 0,
        ]);

        // 2. Create Company
        $company = Company::create([
            'name' => 'MULTI CHUNK TEST SPA',
            'tax_id' => '99.999.999-9',
            'company_code' => '99.999.999-9',
            'fantasy_name' => 'MULTI CHUNK TEST',
            'price_list_id' => $priceList->id,
            'email' => 'test@multichunk.cl',
            'phone_number' => '999999999',
        ]);

        // 3. Create Branch
        $branch = Branch::create([
            'company_id' => $company->id,
            'fantasy_name' => 'SUCURSAL MULTI CHUNK',
            'address' => 'Dirección de prueba multi-chunk',
            'shipping_address' => 'Dirección de despacho multi-chunk',
            'min_price_order' => 0,
        ]);

        // 4. Get or create Role and Permission
        $role = Role::where('name', RoleName::ADMIN->value)->first();
        $permission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        if (!$role) {
            $role = Role::create(['name' => RoleName::ADMIN->value]);
        }

        if (!$permission) {
            $permission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);
        }

        // 5. Create User
        $user = User::create([
            'name' => 'Usuario Multi-Chunk',
            'email' => 'MULTICHUNK@TEST.CL',
            'nickname' => 'MULTICHUNK@TEST.CL',
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

        // 6. Create Category
        $category = Category::create([
            'name' => 'PRODUCTOS TEST MULTI-CHUNK',
            'is_active' => true,
        ]);

        // 7. Create 150 Products (will generate 150 order lines)
        // We'll create products with sequential codes: TEST00000001 to TEST00000150
        for ($i = 1; $i <= 150; $i++) {
            $productCode = 'TEST' . str_pad($i, 8, '0', STR_PAD_LEFT);

            $product = Product::create([
                'code' => $productCode,
                'name' => "Producto Test Multi-Chunk #{$i}",
                'description' => "Producto de prueba para validar merge multi-chunk #{$i}",
                'category_id' => $category->id,
                'measure_unit' => 'UND',
                'weight' => 0,
                'active' => true,
                'allow_sales_without_stock' => true,
            ]);

            // Create price list line for each product
            PriceListLine::create([
                'price_list_id' => $priceList->id,
                'product_id' => $product->id,
                'unit_price' => 1000 + $i, // Varying prices: 1001, 1002, ..., 1150
            ]);
        }

        $this->command->info('✅ Multi-chunk test data seeded successfully!');
        $this->command->info("   - Company: {$company->name}");
        $this->command->info("   - User: {$user->email}");
        $this->command->info("   - Products: 150 (will span 2 chunks)");
        $this->command->info("   - Expected: 1 Order with 150 OrderLines");
    }
}
