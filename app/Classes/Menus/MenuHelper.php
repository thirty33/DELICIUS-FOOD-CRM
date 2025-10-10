<?php

namespace App\Classes\Menus;

use App\Classes\UserPermissions;
use App\Models\Menu;
use Illuminate\Support\Carbon;

class MenuHelper
{

    public static function getMenu($date, $user)
    {
        // First, try to find a menu associated with the user's company
        $companyMenuQuery = Menu::where('publication_date', $date)
            ->where('role_id', UserPermissions::getRole($user)->id)
            ->whereHas('companies', function ($subQuery) use ($user) {
                $subQuery->where('companies.id', $user->company_id);
            });

        if (UserPermissions::getPermission($user)) {
            $companyMenuQuery->where('permissions_id', UserPermissions::getPermission($user)->id);
        }

        // If a company-specific menu exists, return it
        if ($companyMenuQuery->exists()) {
            return $companyMenuQuery;
        }

        // If no company-specific menu found, fall back to general menus (without company association)
        $query = Menu::where('publication_date', $date)
            ->where('role_id', UserPermissions::getRole($user)->id)
            ->whereDoesntHave('companies');

        if (UserPermissions::getPermission($user)) {
            $query->where('permissions_id', UserPermissions::getPermission($user)->id);
        }

        return $query;
    }

    public static function getCurrentMenuQuery($date, $user)
    {
        // First, try to find a menu associated with the user's company
        $companyMenuQuery = Menu::where('publication_date', $date)
            ->where('role_id', UserPermissions::getRole($user)->id)
            ->where('publication_date', '>=', Carbon::now()->startOfDay())
            ->where('active', 1)
            ->whereHas('companies', function ($subQuery) use ($user) {
                $subQuery->where('companies.id', $user->company_id);
            });

        if (UserPermissions::getPermission($user)) {
            $companyMenuQuery->where('permissions_id', UserPermissions::getPermission($user)->id);
        }

        if ($user->allow_late_orders) {
            $companyMenuQuery->where('max_order_date', '>', Carbon::now());
        }

        // If a company-specific menu exists, return it
        if ($companyMenuQuery->exists()) {
            return $companyMenuQuery;
        }

        // If no company-specific menu found, fall back to general menus (without company association)
        $query = Menu::where('publication_date', $date)
            ->where('role_id', UserPermissions::getRole($user)->id)
            ->where('publication_date', '>=', Carbon::now()->startOfDay())
            ->where('active', 1)
            ->whereDoesntHave('companies');

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
    public static function menuExistsForStatusUpdate($date, $roleId, $permissionId, $companyId)
    {
        // First, try to find a menu associated with the company
        $companyMenuExists = Menu::where('publication_date', $date)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->whereHas('companies', function ($subQuery) use ($companyId) {
                $subQuery->where('companies.id', $companyId);
            })
            ->exists();

        // If a company-specific menu exists, return true
        if ($companyMenuExists) {
            return true;
        }

        // If no company-specific menu found, check for general menus (without company association)
        return Menu::where('publication_date', $date)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->whereDoesntHave('companies')
            ->exists();
    }

    /**
     * Check if menu exists for CreateOrUpdateOrderRequest
     * Used in: CreateOrUpdateOrderRequest.php
     */
    public static function menuExistsForOrderCreateUpdate($date, $roleId, $permissionId, $companyId)
    {
        // First, try to find a menu associated with the company
        $companyMenuExists = Menu::where('publication_date', $date)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->whereHas('companies', function ($subQuery) use ($companyId) {
                $subQuery->where('companies.id', $companyId);
            })
            ->exists();

        // If a company-specific menu exists, return true
        if ($companyMenuExists) {
            return true;
        }

        // If no company-specific menu found, check for general menus (without company association)
        return Menu::where('publication_date', $date)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->whereDoesntHave('companies')
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
        $query = Menu::where('publication_date', $publicationDate)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->where('active', $active);

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
     * Check for duplicate menu in update operation with company overlap check
     * Used in: EditMenu.php
     */
    public static function checkDuplicateMenuForUpdate($publicationDate, $roleId, $permissionId, $active, $excludeId, $companyIds = [])
    {
        $query = Menu::where('publication_date', $publicationDate)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->where('active', $active)
            ->where('id', '!=', $excludeId);

        if (!empty($companyIds)) {
            $query->whereHas('companies', function ($subQuery) use ($companyIds) {
                $subQuery->whereIn('companies.id', $companyIds);
            });
        } else {
            $query->whereDoesntHave('companies');
        }

        return $query->exists();
    }
}
