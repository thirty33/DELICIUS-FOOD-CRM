<?php

namespace App\Repositories;

use App\Enums\Filters\CompanyFilters;
use App\Filters\FilterValue;
use App\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pipeline\Pipeline;

class CompanyRepository
{
    /**
     * Get companies filtered by role and/or permission.
     *
     * @param array $filters Available filters:
     *   - 'role': Filter by role name (values: Admin, Café, Convenio)
     *   - 'permission': Filter by permission name (values: Consolidado, Individual)
     */
    public function getFiltered(array $filters = []): Collection
    {
        $baseQuery = Company::query()->orderBy('name');

        $pipelineFilters = [
            CompanyFilters::Role->create(new FilterValue(['role' => $filters['role'] ?? null])),
            CompanyFilters::Permission->create(new FilterValue(['permission' => $filters['permission'] ?? null])),
        ];

        $query = app(Pipeline::class)
            ->send($baseQuery)
            ->through($pipelineFilters)
            ->thenReturn();

        return $query->get();
    }

    /**
     * Get all companies.
     */
    public function all(): Collection
    {
        return Company::orderBy('name')->get();
    }
}