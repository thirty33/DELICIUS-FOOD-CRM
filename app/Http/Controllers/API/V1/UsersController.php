<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\API\V1\ApiResponseService;
use App\Http\Requests\API\V1\User\GetSubordinateUsersRequest;
use App\Http\Resources\API\V1\SubordinateUserResource;
use App\Repositories\MenuRepository;
use App\Models\User;
use Exception;

class UsersController extends Controller
{
    protected $menuRepository;

    public function __construct(MenuRepository $menuRepository)
    {
        $this->menuRepository = $menuRepository;
    }

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
                ->with(['branch'])
                ->get();

            // For each subordinate user, get their available menus (limit to 15)
            foreach ($subordinateUsers as $user) {
                $user->available_menus = $this->menuRepository->getAvailableMenusForUser($user, 15);
            }

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
