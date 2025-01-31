<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\MenuResource;
use App\Models\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class MenuController extends Controller
{
    public function index(): JsonResponse
    {

        $user = auth()->user();
        
        $userRoleIds = $user->roles->pluck('id')->toArray();
        $userPermissionIds = $user->permissions->pluck('id')->toArray();
        
        $query = Menu::where('active', 1)
            ->where('publication_date', '>=', Carbon::now()->startOfDay())
            ->where(function ($q) use ($userRoleIds, $userPermissionIds) {
                if (!empty($userRoleIds)) {
                    $q->whereIn('role_id', $userRoleIds);
                }
                
                if (!empty($userPermissionIds)) {
                    $q->whereIn('permissions_id', $userPermissionIds);
                }
            });
        
        if ($user->allow_late_orders) {
            $query->where('max_order_date', '>', Carbon::now());
        }
        
        $menus = $query->paginate();
    
        return ApiResponseService::success(
            MenuResource::collection($menus)->resource,
            'Active menus retrieved successfully',
        );
    }
}
