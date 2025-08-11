<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\API\V1\ApiResponseService;
use App\Http\Requests\API\V1\User\GetSubordinateUsersRequest;
use App\Http\Resources\API\V1\SubordinateUserResource;
use App\Models\User;
use Exception;

class UsersController extends Controller
{
    /**
     * Get subordinate users for a master user
     * 
     * @param GetSubordinateUsersRequest $request
     * @return JsonResponse
     */
    public function getSubordinateUsers(GetSubordinateUsersRequest $request): JsonResponse
    {
        try {
            $masterUser = $request->user();
            
            // Get users from the same company and branch as the master user
            $subordinateUsers = User::where('company_id', $masterUser->company_id)
                ->where('branch_id', $masterUser->branch_id)
                // ->where('id', '!=', $masterUser->id) // Exclude the master user itself
                ->where('master_user', false) // Only non-master users
                ->with(['branch']) // Load branch relationship
                ->get();
            
            return ApiResponseService::success(
                SubordinateUserResource::collection($subordinateUsers),
                'Subordinate users retrieved successfully'
            );
            
        } catch (Exception $e) {
            return ApiResponseService::unprocessableEntity('error', [
                'message' => [$e->getMessage()],
            ]);
        }
    }
}
