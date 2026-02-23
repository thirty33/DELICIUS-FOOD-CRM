<?php

namespace App\Repositories;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

class PhoneNumberRepository
{
    /**
     * Find the entity (Branch or Company) that owns a phone number.
     * Normalizes numbers by stripping non-digit characters before comparing.
     *
     * @return array{source_type: string, company_id: int, branch_id: int|null}|null
     */
    public function resolveOwner(string $phoneNumber): ?array
    {
        $normalized = preg_replace('/\D/', '', $phoneNumber);

        $branch = $this->findByNormalizedPhone(Branch::query(), 'contact_phone_number', $normalized);

        if ($branch) {
            return [
                'source_type' => 'branch',
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
            ];
        }

        $company = $this->findByNormalizedPhone(Company::query(), 'phone_number', $normalized);

        if ($company) {
            return [
                'source_type' => 'company',
                'company_id' => $company->id,
                'branch_id' => null,
            ];
        }

        return null;
    }

    private function findByNormalizedPhone($query, string $column, string $normalized): ?Model
    {
        return $query
            ->whereRaw("REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '+', ''), '-', '') = ?", [$normalized])
            ->first();
    }
}
