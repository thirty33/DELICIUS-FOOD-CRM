<?php

namespace App\Classes\Menus;

use App\Classes\UserPermissions;
use App\Models\Menu;
use Illuminate\Support\Carbon;

class MenuHelper
{

    public static function getMenu($date, $user)
    {
        $query = Menu::where('publication_date', $date)
            ->where('role_id', UserPermissions::getRole($user)->id);
        
        if (UserPermissions::getPermission($user)) {
            $query->where('permissions_id', UserPermissions::getPermission($user)->id);
        }
        
        return $query;
    }

    public static function getCurrentMenuQuery($date, $user)
    {
        $query = Menu::where('publication_date', $date)
            ->where('role_id', UserPermissions::getRole($user)->id)
            ->where('publication_date', '>=', Carbon::now()->startOfDay())
            ->where('active', 1);
        
        if (UserPermissions::getPermission($user)) {
            $query->where('permissions_id', UserPermissions::getPermission($user)->id);
        }

        if ($user->allow_late_orders) {
            $query->where('max_order_date', '>', Carbon::now());
        }

        return $query;
    }

    /**
     * Check if menu exists for UpdateStatusRequest
     * Used in: UpdateStatusRequest.php
     */
    public static function menuExistsForStatusUpdate($date, $roleId, $permissionId)
    {
        return Menu::where('publication_date', $date)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->exists();
    }

    /**
     * Check if menu exists for CreateOrUpdateOrderRequest
     * Used in: CreateOrUpdateOrderRequest.php
     */
    public static function menuExistsForOrderCreateUpdate($date, $roleId, $permissionId)
    {
        return Menu::where('publication_date', $date)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->exists();
    }

    /**
     * Check for duplicate menu in import process with company overlap check
     * Used in: MenusImport.php
     */
    public static function checkDuplicateMenuForImport($publicationDate, $roleId, $permissionId, $active, $maxOrderDate, $companyIds = [], $excludeId = null)
    {
        $query = Menu::where('publication_date', $publicationDate)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->where('active', $active)
            ->where('max_order_date', $maxOrderDate);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if (!empty($companyIds)) {
            $query->whereHas('companies', function ($subQuery) use ($companyIds) {
                $subQuery->whereIn('companies.id', $companyIds);
            });
        } else {
            $query->whereDoesntHave('companies');
        }

        return $query->exists();
    }

    /**
     * Get existing menu for cloning validation with company overlap check
     * Used in: MenuCloneService.php
     */
    public static function getExistingMenuForClone($publicationDate, $roleId, $permissionId, $companyIds = [])
    {
        $query = Menu::where('publication_date', $publicationDate)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId);

        if (!empty($companyIds)) {
            $query->whereHas('companies', function ($subQuery) use ($companyIds) {
                $subQuery->whereIn('companies.id', $companyIds);
            });
        }

        return $query->first();
    }

    /**
     * Check for duplicate menu in create operation with company overlap check
     * Used in: CreateMenu.php
     */
    public static function checkDuplicateMenuForCreate($publicationDate, $roleId, $permissionId, $active, $companyIds = [])
    {
        \Log::info('MenuHelper::checkDuplicateMenuForCreate called', [
            'publication_date' => $publicationDate,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'active' => $active,
            'company_ids' => $companyIds
        ]);

        $query = Menu::where('publication_date', $publicationDate)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->where('active', $active);

        if (!empty($companyIds)) {
            \Log::info('Adding company overlap filter to create query', ['company_ids' => $companyIds]);
            $query->whereHas('companies', function ($subQuery) use ($companyIds) {
                $subQuery->whereIn('companies.id', $companyIds);
            });
        } else {
            \Log::info('No companies provided - checking only menus without companies');
            $query->whereDoesntHave('companies');
        }

        $exists = $query->exists();
        \Log::info('Create duplicate check result', ['exists' => $exists]);

        return $exists;
    }

    /**
     * Check for duplicate menu in update operation with company overlap check
     * Used in: EditMenu.php
     */
    public static function checkDuplicateMenuForUpdate($publicationDate, $roleId, $permissionId, $active, $excludeId, $companyIds = [])
    {
        \Log::info('MenuHelper::checkDuplicateMenuForUpdate called', [
            'publication_date' => $publicationDate,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'active' => $active,
            'exclude_id' => $excludeId,
            'company_ids' => $companyIds
        ]);

        $query = Menu::where('publication_date', $publicationDate)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->where('active', $active)
            ->where('id', '!=', $excludeId);

        if (!empty($companyIds)) {
            \Log::info('Adding company overlap filter to update query', ['company_ids' => $companyIds]);
            $query->whereHas('companies', function ($subQuery) use ($companyIds) {
                $subQuery->whereIn('companies.id', $companyIds);
            });
        } else {
            \Log::info('No companies provided - checking only menus without companies (except current)');
            $query->whereDoesntHave('companies');
        }

        $exists = $query->exists();
        \Log::info('Update duplicate check result', ['exists' => $exists]);

        return $exists;
    }
}
