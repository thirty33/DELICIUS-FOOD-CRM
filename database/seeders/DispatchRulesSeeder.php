<?php

namespace Database\Seeders;

use App\Models\DispatchRule;
use App\Models\DispatchRuleRange;
use App\Models\Company;
use App\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class DispatchRulesSeeder extends Seeder
{
    private array $processedRules = [];
    private int $rulesCreated = 0;
    private int $rangesCreated = 0;
    private int $companiesAssociated = 0;
    private int $branchesAssociated = 0;
    private array $errors = [];

    /**
     * Hardcoded dispatch rules data
     * Company IDs and Branch IDs are referenced directly (no sensitive data exposed)
     */
    private function getData(): array
    {
        return [
            ['name' => 'DESPACHO MINIMO 120.000', 'priority' => 1, 'active' => 'SI', 'all_companies' => 'NO', 'all_branches' => 'NO', 'company_ids' => '527', 'branch_ids' => '75', 'min_amount' => 0, 'max_amount' => 120000, 'dispatch_cost' => 8000],
            ['name' => 'DESPACHO MINIMO 120.000', 'priority' => 1, 'active' => 'SI', 'all_companies' => 'NO', 'all_branches' => 'NO', 'company_ids' => '527', 'branch_ids' => '75', 'min_amount' => 120001, 'max_amount' => null, 'dispatch_cost' => 0],

            ['name' => 'DESPACHO MINIMO 60.000', 'priority' => 1, 'active' => 'SI', 'all_companies' => 'NO', 'all_branches' => 'NO', 'company_ids' => '568,584,529,583', 'branch_ids' => '172,173,174,175,176,177,178,179,180,181,182,183,184,185,186,187,188,189,190,191,192,193,194,195,196,197,198,199,200,201,202,203,204,205,206,207,208,209,210,211,212,213,214,215,216,217,218,219,220,221,222,230,231,232,233,234,235,236,237,238,240,255', 'min_amount' => 0, 'max_amount' => 60000, 'dispatch_cost' => 8000],
            ['name' => 'DESPACHO MINIMO 60.000', 'priority' => 1, 'active' => 'SI', 'all_companies' => 'NO', 'all_branches' => 'NO', 'company_ids' => '568,584,529,583', 'branch_ids' => '172,173,174,175,176,177,178,179,180,181,182,183,184,185,186,187,188,189,190,191,192,193,194,195,196,197,198,199,200,201,202,203,204,205,206,207,208,209,210,211,212,213,214,215,216,217,218,219,220,221,222,230,231,232,233,234,235,236,237,238,240,255', 'min_amount' => 60001, 'max_amount' => null, 'dispatch_cost' => 0],

            ['name' => 'DESPACHO MINIMO 70.000', 'priority' => 1, 'active' => 'SI', 'all_companies' => 'NO', 'all_branches' => 'NO', 'company_ids' => '546,553,569,552,500,520,542,574,581,517,498,519,512,492,538,533,547,509,510,494,578,530,541,531,534,535,539,555,554,511,495,502,523,521,580,513,503,550,577,504,525,545,543,532,575,536,489', 'branch_ids' => '24,33,34,35,38,39,45,47,49,50,51,56,57,58,59,60,65,67,68,69,71,73,78,79,80,81,82,83,84,85,86,87,88,91,92,94,95,99,101,102,103,106,108,109,110,111,223,224,225,226,227,244,245,246,247', 'min_amount' => 0, 'max_amount' => 70000, 'dispatch_cost' => 8000],
            ['name' => 'DESPACHO MINIMO 70.000', 'priority' => 1, 'active' => 'SI', 'all_companies' => 'NO', 'all_branches' => 'NO', 'company_ids' => '546,553,569,552,500,520,542,574,581,517,498,519,512,492,538,533,547,509,510,494,578,530,541,531,534,535,539,555,554,511,495,502,523,521,580,513,503,550,577,504,525,545,543,532,575,536,489', 'branch_ids' => '24,33,34,35,38,39,45,47,49,50,51,56,57,58,59,60,65,67,68,69,71,73,78,79,80,81,82,83,84,85,86,87,88,91,92,94,95,99,101,102,103,106,108,109,110,111,223,224,225,226,227,244,245,246,247', 'min_amount' => 70001, 'max_amount' => null, 'dispatch_cost' => 0],

            ['name' => 'DESPACHO MINIMO 90.000', 'priority' => 1, 'active' => 'SI', 'all_companies' => 'NO', 'all_branches' => 'NO', 'company_ids' => '537,514,549,576,579,488,507,544,516,570,515,524,496,526,571,506,548,551,508,490,493,518,497,540,501,505,582,572,573,499', 'branch_ids' => '25,26,27,36,37,40,41,42,43,44,46,48,52,53,54,55,61,62,63,64,66,72,74,89,90,93,96,97,98,100,104,105,107,228,229,241,242,243,248,249,250,251', 'min_amount' => 0, 'max_amount' => 90000, 'dispatch_cost' => 8000],
            ['name' => 'DESPACHO MINIMO 90.000', 'priority' => 1, 'active' => 'SI', 'all_companies' => 'NO', 'all_branches' => 'NO', 'company_ids' => '537,514,549,576,579,488,507,544,516,570,515,524,496,526,571,506,548,551,508,490,493,518,497,540,501,505,582,572,573,499', 'branch_ids' => '25,26,27,36,37,40,41,42,43,44,46,48,52,53,54,55,61,62,63,64,66,72,74,89,90,93,96,97,98,100,104,105,107,228,229,241,242,243,248,249,250,251', 'min_amount' => 90001, 'max_amount' => null, 'dispatch_cost' => 0],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Starting Dispatch Rules Seeder...');
        $this->command->newLine();

        DB::beginTransaction();

        try {
            $data = $this->getData();
            $this->command->info("📊 Processing " . count($data) . " rows...");
            $this->command->newLine();

            foreach ($data as $index => $row) {
                $this->processRow($row, $index + 1);
            }

            DB::commit();
            $this->showSummary();

        } catch (Exception $e) {
            DB::rollBack();
            $this->command->error("❌ Transaction rolled back: " . $e->getMessage());
            Log::error('DispatchRulesSeeder: Transaction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Process a single row
     */
    private function processRow(array $row, int $rowNumber): void
    {
        try {
            $ruleName = $row['name'];

            // Create or get rule
            $rule = $this->createOrGetRule($row, $rowNumber);

            // Create range
            $this->createRange($rule, $row, $rowNumber);

            // Associate companies and branches (only once per rule)
            if (empty($this->processedRules[$ruleName]['associations_done'])) {
                $this->associateCompanies($rule, $row, $rowNumber);
                $this->associateBranches($rule, $row, $rowNumber);
                $this->processedRules[$ruleName]['associations_done'] = true;
            }

        } catch (Exception $e) {
            $error = "Row {$rowNumber} ({$row['name']}): " . $e->getMessage();
            $this->errors[] = $error;
            $this->command->warn("⚠️  " . $error);

            Log::error('DispatchRulesSeeder: Row processing failed', [
                'row' => $rowNumber,
                'rule_name' => $row['name'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Create or get existing dispatch rule
     */
    private function createOrGetRule(array $row, int $rowNumber): DispatchRule
    {
        $ruleName = $row['name'];

        if (isset($this->processedRules[$ruleName])) {
            return $this->processedRules[$ruleName]['model'];
        }

        try {
            $rule = DispatchRule::firstOrCreate(
                ['name' => $ruleName],
                [
                    'priority' => $row['priority'],
                    'active' => $this->convertToBoolean($row['active']),
                    'all_companies' => $this->convertToBoolean($row['all_companies']),
                    'all_branches' => $this->convertToBoolean($row['all_branches']),
                ]
            );

            if ($rule->wasRecentlyCreated) {
                $this->rulesCreated++;
                $this->command->info("✅ Created rule: {$ruleName}");
            } else {
                $this->command->info("ℹ️  Existing rule: {$ruleName}");
            }

            $this->processedRules[$ruleName] = ['model' => $rule, 'associations_done' => false];

            return $rule;

        } catch (Exception $e) {
            Log::error('DispatchRulesSeeder: Failed to create rule', [
                'row' => $rowNumber,
                'rule_name' => $ruleName,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to create rule: " . $e->getMessage());
        }
    }

    /**
     * Create dispatch rule range
     * Values are converted to cents for MoneyInput compatibility
     */
    private function createRange(DispatchRule $rule, array $row, int $rowNumber): void
    {
        try {
            // Convert to cents (multiply by 100) for MoneyInput compatibility
            $minAmountInCents = $row['min_amount'] * 100;
            $maxAmountInCents = $row['max_amount'] !== null ? $row['max_amount'] * 100 : null;
            $dispatchCostInCents = $row['dispatch_cost'] * 100;

            $range = DispatchRuleRange::firstOrCreate(
                [
                    'dispatch_rule_id' => $rule->id,
                    'min_amount' => $minAmountInCents,
                ],
                [
                    'max_amount' => $maxAmountInCents,
                    'dispatch_cost' => $dispatchCostInCents,
                ]
            );

            if ($range->wasRecentlyCreated) {
                $this->rangesCreated++;
                // Display in original currency (divide by 100)
                $maxText = $range->max_amount ? '$' . number_format($range->max_amount / 100, 0, ',', '.') : 'Unlimited';
                $this->command->info("   ➕ Range: $" . number_format($range->min_amount / 100, 0, ',', '.') . " - {$maxText} → $" . number_format($range->dispatch_cost / 100, 0, ',', '.'));
            }

        } catch (Exception $e) {
            Log::error('DispatchRulesSeeder: Failed to create range', [
                'row' => $rowNumber,
                'rule_id' => $rule->id,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to create range: " . $e->getMessage());
        }
    }

    /**
     * Associate companies with rule
     * Now uses company IDs directly (no sensitive data lookup)
     */
    private function associateCompanies(DispatchRule $rule, array $row, int $rowNumber): void
    {
        if ($rule->all_companies || empty($row['company_ids'])) {
            return;
        }

        try {
            $companyIds = $this->parseCommaSeparated($row['company_ids']);
            $companies = Company::whereIn('id', $companyIds)->get();

            if ($companies->count() < count($companyIds)) {
                $found = $companies->pluck('id')->toArray();
                $missing = array_diff($companyIds, $found);

                Log::warning('DispatchRulesSeeder: Companies not found', [
                    'row' => $rowNumber,
                    'rule' => $rule->name,
                    'missing_ids' => $missing
                ]);

                $this->command->warn("   ⚠️  Missing companies: " . count($missing));
            }

            if ($companies->isNotEmpty()) {
                $rule->companies()->syncWithoutDetaching($companies->pluck('id'));
                $this->companiesAssociated += $companies->count();
                $this->command->info("   🏢 Associated {$companies->count()} companies");
            }

        } catch (Exception $e) {
            Log::error('DispatchRulesSeeder: Failed to associate companies', [
                'row' => $rowNumber,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to associate companies: " . $e->getMessage());
        }
    }

    /**
     * Associate branches with rule
     * Now uses branch IDs directly (no sensitive data lookup)
     */
    private function associateBranches(DispatchRule $rule, array $row, int $rowNumber): void
    {
        if ($rule->all_branches || empty($row['branch_ids'])) {
            return;
        }

        try {
            $branchIds = $this->parseCommaSeparated($row['branch_ids']);
            $branches = Branch::whereIn('id', $branchIds)->get();

            if ($branches->count() < count($branchIds)) {
                $found = $branches->pluck('id')->toArray();
                $missing = array_diff($branchIds, $found);

                Log::warning('DispatchRulesSeeder: Branches not found', [
                    'row' => $rowNumber,
                    'rule' => $rule->name,
                    'missing_ids' => $missing
                ]);

                $this->command->warn("   ⚠️  Missing branches: " . count($missing));
            }

            if ($branches->isNotEmpty()) {
                $rule->branches()->syncWithoutDetaching($branches->pluck('id'));
                $this->branchesAssociated += $branches->count();
                $this->command->info("   🏪 Associated {$branches->count()} branches");
            }

        } catch (Exception $e) {
            Log::error('DispatchRulesSeeder: Failed to associate branches', [
                'row' => $rowNumber,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to associate branches: " . $e->getMessage());
        }
    }

    /**
     * Convert SI/NO to boolean
     */
    private function convertToBoolean(string $value): bool
    {
        return in_array(strtoupper(trim($value)), ['SI', 'S', 'YES', 'Y', '1', 'TRUE']);
    }

    /**
     * Parse comma-separated list
     */
    private function parseCommaSeparated(string $value): array
    {
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Show summary
     */
    private function showSummary(): void
    {
        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════════════════════');
        $this->command->info('✅ DISPATCH RULES SEEDER COMPLETED');
        $this->command->info('═══════════════════════════════════════════════════════════');
        $this->command->info("📋 Rules created: {$this->rulesCreated}");
        $this->command->info("💰 Ranges created: {$this->rangesCreated}");
        $this->command->info("🏢 Companies associated: {$this->companiesAssociated}");
        $this->command->info("🏪 Branches associated: {$this->branchesAssociated}");

        if (count($this->errors) > 0) {
            $this->command->warn("⚠️  Errors: " . count($this->errors));
            $this->command->warn("Check storage/logs/laravel.log for details");
        } else {
            $this->command->info("✅ No errors");
        }

        $this->command->info('═══════════════════════════════════════════════════════════');
    }
}