<?php

namespace App\Contracts;

interface DeletionServiceInterface
{
    /**
     * Delete a single record with all related data
     *
     * @param int $id
     * @return bool
     */
    public function deleteSingle(int $id): bool;

    /**
     * Delete multiple records with all related data
     *
     * @param array $ids
     * @return int Number of records dispatched for deletion
     */
    public function deleteMultiple(array $ids): int;
}