<?php

namespace App\Repositories;

use App\Models\User;
use App\Filters\FilterValue;
use App\Enums\Filters\UserFilters;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserRepository
{
    /**
     * Get subordinate users for a master user.
     *
     * If the user is a super_master_user, returns users from all companies.
     * Otherwise, returns only users from the same company.
     *
     * @param User $masterUser
     * @param int $perPage
     * @param array $searchFilters Additional search filters:
     *   - 'company_search': Search in company name, fantasy_name, tax_id, company_code
     *   - 'branch_search': Search in branch fantasy_name, address, branch_code
     *   - 'user_search': Search in user nickname, name, email
     * @return LengthAwarePaginator
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
            UserFilters::Sort->create(new FilterValue(null)),
        ];

        $query = app(Pipeline::class)
            ->send($baseQuery)
            ->through($filters)
            ->thenReturn();

        return $query->paginate($perPage);
    }
}