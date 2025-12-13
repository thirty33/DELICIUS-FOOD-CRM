<?php

namespace Tests\Helpers;

use App\Models\ReportConfiguration;
use App\Models\ReportGrouper;

/**
 * Helper trait for testing Report Groupers
 *
 * Provides reusable functions for:
 * - Creating ReportConfiguration with groupers enabled
 * - Creating ReportGrouper instances with companies/branches
 * - Creating groupers that emulate branch columns (for migration from branch-based to grouper-based reports)
 */
trait ReportGrouperTestHelper
{
    /**
     * The active report configuration for tests
     */
    protected ?ReportConfiguration $reportConfiguration = null;

    /**
     * Array of created groupers for reference
     */
    protected array $createdGroupers = [];

    /**
     * Create a ReportConfiguration with groupers enabled
     *
     * @param string $name Configuration name
     * @param bool $useGroupers Whether to use groupers
     * @param bool $excludeCafeterias Whether to exclude cafeterias
     * @param bool $excludeAgreements Whether to exclude agreements
     * @return ReportConfiguration
     */
    protected function createReportConfiguration(
        string $name = 'test_config',
        bool $useGroupers = true,
        bool $excludeCafeterias = false,
        bool $excludeAgreements = false
    ): ReportConfiguration {
        $this->reportConfiguration = ReportConfiguration::create([
            'name' => $name,
            'description' => 'Test configuration for groupers',
            'use_groupers' => $useGroupers,
            'exclude_cafeterias' => $excludeCafeterias,
            'exclude_agreements' => $excludeAgreements,
            'is_active' => true,
        ]);

        return $this->reportConfiguration;
    }

    /**
     * Get or create the default report configuration
     *
     * @return ReportConfiguration
     */
    protected function getOrCreateReportConfiguration(): ReportConfiguration
    {
        if ($this->reportConfiguration === null) {
            $this->createReportConfiguration();
        }

        return $this->reportConfiguration;
    }

    /**
     * Create a single ReportGrouper
     *
     * @param string $name Grouper name (will appear as column header)
     * @param string $code Unique code for the grouper
     * @param int $displayOrder Order in which the column appears
     * @param bool $isActive Whether the grouper is active
     * @return ReportGrouper
     */
    protected function createReportGrouper(
        string $name,
        string $code,
        int $displayOrder = 1,
        bool $isActive = true
    ): ReportGrouper {
        $config = $this->getOrCreateReportConfiguration();

        $grouper = ReportGrouper::create([
            'report_configuration_id' => $config->id,
            'name' => $name,
            'code' => $code,
            'display_order' => $displayOrder,
            'is_active' => $isActive,
        ]);

        $this->createdGroupers[] = $grouper;

        return $grouper;
    }

    /**
     * Create a ReportGrouper and attach companies to it
     *
     * @param string $name Grouper name
     * @param string $code Unique code
     * @param array $companyIds Array of Company IDs to attach
     * @param int $displayOrder Order in which the column appears
     * @return ReportGrouper
     */
    protected function createReportGrouperWithCompanies(
        string $name,
        string $code,
        array $companyIds,
        int $displayOrder = 1
    ): ReportGrouper {
        $grouper = $this->createReportGrouper($name, $code, $displayOrder);

        foreach ($companyIds as $companyId) {
            $grouper->companies()->attach($companyId);
        }

        return $grouper;
    }

    /**
     * Create a ReportGrouper and attach branches to it
     *
     * @param string $name Grouper name
     * @param string $code Unique code
     * @param array $branchIds Array of Branch IDs to attach
     * @param int $displayOrder Order in which the column appears
     * @return ReportGrouper
     */
    protected function createReportGrouperWithBranches(
        string $name,
        string $code,
        array $branchIds,
        int $displayOrder = 1
    ): ReportGrouper {
        $grouper = $this->createReportGrouper($name, $code, $displayOrder);

        foreach ($branchIds as $branchId) {
            $grouper->branches()->attach($branchId);
        }

        return $grouper;
    }

    /**
     * Create groupers that emulate branch columns
     *
     * This method creates one grouper per branch, using the branch's fantasy_name
     * as the grouper name. This allows migrating from branch-based columns to
     * grouper-based columns without changing test assertions.
     *
     * @param array $branches Array of Branch instances
     * @return array Array of created ReportGrouper instances
     *
     * @example
     * // If branches are: OTERO HORECA, ALIACE HORECA, UNICON LO ESPEJO
     * // Creates groupers with same names, each containing one company
     * $groupers = $this->createGroupersFromBranches([$branchOtero, $branchAliace, $branchUnicon]);
     */
    protected function createGroupersFromBranches(array $branches): array
    {
        $groupers = [];
        $displayOrder = 1;

        foreach ($branches as $branch) {
            $name = $branch->fantasy_name;
            $code = $this->generateGrouperCode($name);

            $grouper = $this->createReportGrouper($name, $code, $displayOrder);

            // Attach the company that owns this branch
            $grouper->companies()->attach($branch->company_id);

            $groupers[] = $grouper;
            $displayOrder++;
        }

        return $groupers;
    }

    /**
     * Create groupers from company-branch pairs
     *
     * Each pair creates one grouper with the branch name, attached to the company.
     *
     * @param array $companyBranchPairs Array of ['company' => Company, 'branch' => Branch]
     * @return array Array of created ReportGrouper instances
     *
     * @example
     * $groupers = $this->createGroupersFromCompanyBranchPairs([
     *     ['company' => $companyOtero, 'branch' => $branchOtero],
     *     ['company' => $companyAliace, 'branch' => $branchAliace],
     * ]);
     */
    protected function createGroupersFromCompanyBranchPairs(array $companyBranchPairs): array
    {
        $groupers = [];
        $displayOrder = 1;

        foreach ($companyBranchPairs as $pair) {
            $company = $pair['company'];
            $branch = $pair['branch'];

            $name = $branch->fantasy_name;
            $code = $this->generateGrouperCode($name);

            $grouper = $this->createReportGrouper($name, $code, $displayOrder);
            $grouper->companies()->attach($company->id);

            $groupers[] = $grouper;
            $displayOrder++;
        }

        return $groupers;
    }

    /**
     * Create multiple groupers with companies in bulk
     *
     * @param array $grouperDefinitions Array of grouper definitions
     *        Each definition: ['name' => string, 'code' => string, 'company_ids' => array]
     * @return array Array of created ReportGrouper instances
     *
     * @example
     * $groupers = $this->createMultipleGroupersWithCompanies([
     *     ['name' => 'ZONA NORTE', 'code' => 'ZN', 'company_ids' => [$company1->id, $company2->id]],
     *     ['name' => 'ZONA SUR', 'code' => 'ZS', 'company_ids' => [$company3->id]],
     * ]);
     */
    protected function createMultipleGroupersWithCompanies(array $grouperDefinitions): array
    {
        $groupers = [];
        $displayOrder = 1;

        foreach ($grouperDefinitions as $definition) {
            $grouper = $this->createReportGrouperWithCompanies(
                $definition['name'],
                $definition['code'],
                $definition['company_ids'],
                $displayOrder
            );

            $groupers[] = $grouper;
            $displayOrder++;
        }

        return $groupers;
    }

    /**
     * Create groupers by names with their associated companies
     *
     * Simple method to create groupers passing name-company pairs directly.
     * Code is auto-generated from name. Display order follows array order.
     *
     * @param array $nameCompanyPairs Array of ['name' => string, 'company_id' => int]
     * @return array Array of created ReportGrouper instances
     *
     * @example
     * $groupers = $this->createGroupersByName([
     *     ['name' => 'OTERO HORECA', 'company_id' => $companyOtero->id],
     *     ['name' => 'ALIACE HORECA', 'company_id' => $companyAliace->id],
     * ]);
     */
    protected function createGroupersByName(array $nameCompanyPairs): array
    {
        $groupers = [];
        $displayOrder = 1;

        foreach ($nameCompanyPairs as $pair) {
            $name = $pair['name'];
            $companyId = $pair['company_id'];
            $code = $this->generateGrouperCode($name);

            $grouper = $this->createReportGrouper($name, $code, $displayOrder);
            $grouper->companies()->attach($companyId);

            $groupers[] = $grouper;
            $displayOrder++;
        }

        return $groupers;
    }

    /**
     * Create groupers by names with their associated branches
     *
     * Similar to createGroupersByName but uses branches instead of companies.
     * This is useful when different branches of the same company need
     * to appear as separate columns.
     *
     * @param array $nameBranchPairs Array of ['name' => string, 'branch_id' => int]
     * @return array Array of created ReportGrouper instances
     *
     * @example
     * $groupers = $this->createGroupersByBranchName([
     *     ['name' => 'BRANCH A HORECA', 'branch_id' => $branchA->id],
     *     ['name' => 'BRANCH B HORECA', 'branch_id' => $branchB->id],
     * ]);
     */
    protected function createGroupersByBranchName(array $nameBranchPairs): array
    {
        $groupers = [];
        $displayOrder = 1;

        foreach ($nameBranchPairs as $pair) {
            $name = $pair['name'];
            $branchId = $pair['branch_id'];
            $code = $this->generateGrouperCode($name);

            $grouper = $this->createReportGrouper($name, $code, $displayOrder);
            $grouper->branches()->attach($branchId);

            $groupers[] = $grouper;
            $displayOrder++;
        }

        return $groupers;
    }

    /**
     * Generate a unique code from a name
     *
     * @param string $name The name to convert to code
     * @return string Uppercase code with underscores
     */
    protected function generateGrouperCode(string $name): string
    {
        $code = strtoupper($name);
        $code = preg_replace('/[^A-Z0-9]/', '_', $code);
        $code = preg_replace('/_+/', '_', $code);
        $code = trim($code, '_');

        return $code;
    }

    /**
     * Get all created groupers
     *
     * @return array
     */
    protected function getCreatedGroupers(): array
    {
        return $this->createdGroupers;
    }

    /**
     * Get grouper names in display order
     *
     * @return array Array of grouper names
     */
    protected function getGrouperNamesInOrder(): array
    {
        return collect($this->createdGroupers)
            ->sortBy('display_order')
            ->pluck('name')
            ->toArray();
    }

    /**
     * Reset groupers state (useful between tests)
     */
    protected function resetGroupersState(): void
    {
        $this->reportConfiguration = null;
        $this->createdGroupers = [];
    }
}