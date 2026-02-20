<?php

namespace App\Repositories;

use App\Enums\Filters\UserFilters;
use App\Filters\FilterValue;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;

class UserRepository
{
    /**
     * Get subordinate users for a master user.
     *
     * If the user is a super_master_user, returns users from all companies.
     * Otherwise, returns only users from the same company.
     *
     * @param  array  $searchFilters  Additional search filters:
     *                                - 'company_search': Search in company name, fantasy_name, tax_id, company_code
     *                                - 'branch_search': Search in branch fantasy_name, address, branch_code
     *                                - 'user_search': Search in user nickname, name, email
     *                                - 'user_role': Filter by role name (exact match, values: Admin, Café, Convenio)
     *                                - 'user_permission': Filter by permission name (exact match, values: Consolidado, Individual)
     */
    public function getSubordinateUsers(User $masterUser, int $perPage = 15, array $searchFilters = []): LengthAwarePaginator
    {
        $baseQuery = User::query()->with(['branch', 'company']);

        $filters = [
            UserFilters::ExcludeMasterUsers->create(new FilterValue(null)),
            UserFilters::Company->create(new FilterValue(['master_user' => $masterUser])),
            UserFilters::CompanySearch->create(new FilterValue(['search' => $searchFilters['company_search'] ?? null])),
            UserFilters::BranchSearch->create(new FilterValue(['search' => $searchFilters['branch_search'] ?? null])),
            UserFilters::UserSearch->create(new FilterValue(['search' => $searchFilters['user_search'] ?? null])),
            UserFilters::UserRole->create(new FilterValue(['role' => $searchFilters['user_role'] ?? null])),
            UserFilters::UserPermission->create(new FilterValue(['permission' => $searchFilters['user_permission'] ?? null])),
            UserFilters::Sort->create(new FilterValue(null)),
        ];

        $query = app(Pipeline::class)
            ->send($baseQuery)
            ->through($filters)
            ->thenReturn();

        return $query->paginate($perPage);
    }

    public function getSellers(): Collection
    {
        return User::query()
            ->where('is_seller', true)
            ->orderBy('nickname')
            ->get();
    }

    public function getClientsForPortfolioSync(): Collection
    {
        return User::query()
            ->whereNotNull('seller_id')
            ->where('is_seller', false)
            ->with(['seller.sellerPortfolios', 'activePortfolio.portfolio', 'branch'])
            ->get();
    }
}
