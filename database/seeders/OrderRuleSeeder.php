<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\OrderRule;
use App\Models\OrderRuleSubcategoryExclusion;
use App\Models\OrderRuleSubcategoryLimit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subcategory;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Illuminate\Database\Seeder;

class OrderRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder creates the current production Order Rules configuration:
     *
     * EXCLUSION RULES:
     * Rule 1 (General - Priority 100):
     * - PLATO DE FONDO cannot combine with PLATO DE FONDO
     * - ENTRADA cannot combine with ENTRADA
     * - FRIA cannot combine with HIPOCALORICO
     * - PAN DE ACOMPAÑAMIENTO cannot combine with SANDWICH
     *
     * Rule 2 (UNICON Companies - Priority 99):
     * - PLATO DE FONDO cannot combine with PLATO DE FONDO
     * - FRIA cannot combine with HIPOCALORICO
     * - PAN DE ACOMPAÑAMIENTO cannot combine with SANDWICH
     * - NOTE: Does NOT restrict ENTRADA (allows multiple ENTRADA products)
     * - Applies to: UNICON COLACIONES, UNICON ALMUERZOS, UNICON CENAS
     *
     * LIMIT RULES:
     * Rule 3 (General Limits - Priority 100):
     * - Max 1 PLATO DE FONDO per order
     * - Max 1 ENTRADA per order
     * - Max 1 CALIENTE per order
     * - Max 1 FRIA per order
     * - Max 1 PAN DE ACOMPAÑAMIENTO per order
     *
     * Rule 4 (UNICON Companies Limits - Priority 99):
     * - Max 1 PLATO DE FONDO per order
     * - Max 2 ENTRADA per order (overrides general)
     * - Max 2 CALIENTE per order (overrides general)
     * - Max 1 FRIA per order
     * - Max 1 PAN DE ACOMPAÑAMIENTO per order
     * - Applies to: UNICON COLACIONES, UNICON ALMUERZOS, UNICON CENAS
     */
    public function run(): void
    {
        // Get or create Role and Permission
        $agreementRole = Role::firstOrCreate(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::firstOrCreate(['name' => PermissionName::INDIVIDUAL->value]);

        // Get subcategories
        $platoFondo = Subcategory::where('name', 'PLATO DE FONDO')->first();
        $entrada = Subcategory::where('name', 'ENTRADA')->first();
        $caliente = Subcategory::where('name', 'CALIENTE')->first();
        $fria = Subcategory::where('name', 'FRIA')->first();
        $hipocalorico = Subcategory::where('name', 'HIPOCALORICO')->first();
        $panAcompanamiento = Subcategory::where('name', 'PAN DE ACOMPAÑAMIENTO')->first();
        $sandwich = Subcategory::where('name', 'SANDWICH')->first();

        // Rule 1: General exclusion rule (Priority 100)
        $generalRule = OrderRule::firstOrCreate(
            [
                'rule_type' => 'subcategory_exclusion',
                'priority' => 100,
                'role_id' => $agreementRole->id,
                'permission_id' => $individualPermission->id,
            ],
            [
                'name' => 'Exclusión general de subcategorías',
                'description' => 'Reglas de exclusión de subcategorías para convenios individuales (general)',
                'is_active' => true,
            ]
        );

        // Add exclusions for general rule
        if ($platoFondo) {
            OrderRuleSubcategoryExclusion::firstOrCreate([
                'order_rule_id' => $generalRule->id,
                'subcategory_id' => $platoFondo->id,
                'excluded_subcategory_id' => $platoFondo->id,
            ]);
        }

        if ($entrada) {
            OrderRuleSubcategoryExclusion::firstOrCreate([
                'order_rule_id' => $generalRule->id,
                'subcategory_id' => $entrada->id,
                'excluded_subcategory_id' => $entrada->id,
            ]);
        }

        if ($fria && $hipocalorico) {
            OrderRuleSubcategoryExclusion::firstOrCreate([
                'order_rule_id' => $generalRule->id,
                'subcategory_id' => $fria->id,
                'excluded_subcategory_id' => $hipocalorico->id,
            ]);
        }

        if ($panAcompanamiento && $sandwich) {
            OrderRuleSubcategoryExclusion::firstOrCreate([
                'order_rule_id' => $generalRule->id,
                'subcategory_id' => $panAcompanamiento->id,
                'excluded_subcategory_id' => $sandwich->id,
            ]);
        }

        // Rule 2: UNICON Companies exclusion rule (Priority 99)
        $uniconRule = OrderRule::firstOrCreate(
            [
                'rule_type' => 'subcategory_exclusion',
                'priority' => 99,
                'role_id' => $agreementRole->id,
                'permission_id' => $individualPermission->id,
            ],
            [
                'name' => 'Exclusión de subcategorías (UNICON)',
                'description' => 'Reglas de exclusión de subcategorías para empresas UNICON (permite múltiples ENTRADA)',
                'is_active' => true,
            ]
        );

        // Add exclusions for UNICON rule (NO ENTRADA restriction)
        if ($platoFondo) {
            OrderRuleSubcategoryExclusion::firstOrCreate([
                'order_rule_id' => $uniconRule->id,
                'subcategory_id' => $platoFondo->id,
                'excluded_subcategory_id' => $platoFondo->id,
            ]);
        }

        if ($fria && $hipocalorico) {
            OrderRuleSubcategoryExclusion::firstOrCreate([
                'order_rule_id' => $uniconRule->id,
                'subcategory_id' => $fria->id,
                'excluded_subcategory_id' => $hipocalorico->id,
            ]);
        }

        if ($panAcompanamiento && $sandwich) {
            OrderRuleSubcategoryExclusion::firstOrCreate([
                'order_rule_id' => $uniconRule->id,
                'subcategory_id' => $panAcompanamiento->id,
                'excluded_subcategory_id' => $sandwich->id,
            ]);
        }

        // Associate UNICON companies with Rule 2
        $uniconCompanies = Company::where('tax_id', '76.756.988-2')
            ->whereIn('company_code', [
                '76.756.988-2COLA',
                '76.756.988-2FULLALM',
                '76.756.988-2FULLCENA',
            ])
            ->get();

        foreach ($uniconCompanies as $company) {
            // Using syncWithoutDetaching to avoid duplicate entries
            $uniconRule->companies()->syncWithoutDetaching([$company->id]);
        }

        // ========================================
        // PRODUCT LIMIT RULES
        // ========================================

        // Rule 3: General Limit Rule (Priority 100)
        $generalLimitRule = OrderRule::firstOrCreate(
            [
                'rule_type' => 'product_limit_per_subcategory',
                'priority' => 100,
                'role_id' => $agreementRole->id,
                'permission_id' => $individualPermission->id,
            ],
            [
                'name' => 'Límite general de productos por subcategoría',
                'description' => 'Límite máximo de productos por subcategoría para convenios individuales (general)',
                'is_active' => true,
            ]
        );

        // Add limits for general rule
        if ($platoFondo) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $generalLimitRule->id,
                'subcategory_id' => $platoFondo->id,
            ], [
                'max_products' => 1,
            ]);
        }

        if ($entrada) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $generalLimitRule->id,
                'subcategory_id' => $entrada->id,
            ], [
                'max_products' => 1,
            ]);
        }

        if ($caliente) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $generalLimitRule->id,
                'subcategory_id' => $caliente->id,
            ], [
                'max_products' => 1,
            ]);
        }

        if ($fria) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $generalLimitRule->id,
                'subcategory_id' => $fria->id,
            ], [
                'max_products' => 1,
            ]);
        }

        if ($panAcompanamiento) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $generalLimitRule->id,
                'subcategory_id' => $panAcompanamiento->id,
            ], [
                'max_products' => 1,
            ]);
        }

        // Rule 4: UNICON Companies Limit Rule (Priority 99)
        $uniconLimitRule = OrderRule::firstOrCreate(
            [
                'rule_type' => 'product_limit_per_subcategory',
                'priority' => 99,
                'role_id' => $agreementRole->id,
                'permission_id' => $individualPermission->id,
            ],
            [
                'name' => 'Límite de productos por subcategoría (UNICON)',
                'description' => 'Límite máximo de productos por subcategoría para empresas UNICON (permite 2 ENTRADA y 2 CALIENTE)',
                'is_active' => true,
            ]
        );

        // Add limits for UNICON rule (2 ENTRADA and 2 CALIENTE allowed)
        if ($platoFondo) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $uniconLimitRule->id,
                'subcategory_id' => $platoFondo->id,
            ], [
                'max_products' => 1,
            ]);
        }

        if ($entrada) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $uniconLimitRule->id,
                'subcategory_id' => $entrada->id,
            ], [
                'max_products' => 2, // UNICON allows 2 ENTRADA
            ]);
        }

        if ($caliente) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $uniconLimitRule->id,
                'subcategory_id' => $caliente->id,
            ], [
                'max_products' => 2, // UNICON allows 2 CALIENTE
            ]);
        }

        if ($fria) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $uniconLimitRule->id,
                'subcategory_id' => $fria->id,
            ], [
                'max_products' => 1,
            ]);
        }

        if ($panAcompanamiento) {
            OrderRuleSubcategoryLimit::firstOrCreate([
                'order_rule_id' => $uniconLimitRule->id,
                'subcategory_id' => $panAcompanamiento->id,
            ], [
                'max_products' => 1,
            ]);
        }

        // Associate UNICON companies with limit rule
        foreach ($uniconCompanies as $company) {
            $uniconLimitRule->companies()->syncWithoutDetaching([$company->id]);
        }

        $this->command->info('Order Rules seeded successfully!');
        $this->command->info("Exclusion Rules:");
        $this->command->info("  - General Exclusion Rule ID: {$generalRule->id} (Priority 100)");
        $this->command->info("  - UNICON Exclusion Rule ID: {$uniconRule->id} (Priority 99)");
        $this->command->info("Limit Rules:");
        $this->command->info("  - General Limit Rule ID: {$generalLimitRule->id} (Priority 100)");
        $this->command->info("  - UNICON Limit Rule ID: {$uniconLimitRule->id} (Priority 99)");
        $this->command->info("UNICON Companies associated: {$uniconCompanies->count()}");
    }
}
