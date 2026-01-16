<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\API\V1\ApiResponseService;
use App\Http\Requests\API\V1\User\GetSubordinateUsersRequest;
use App\Http\Resources\API\V1\SubordinateUserResourceCollection;
use App\Repositories\MenuRepository;
use App\Repositories\UserRepository;
use Exception;

class UsersController extends Controller
{
    protected MenuRepository $menuRepository;
    protected UserRepository $userRepository;

    public function __construct(MenuRepository $menuRepository, UserRepository $userRepository)
    {
        $this->menuRepository = $menuRepository;
        $this->userRepository = $userRepository;
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
            $perPage = $request->input('per_page', 15);
            $userSearchFilters = $request->getUserSearchFilters();
            $menuSearchFilters = $request->getMenuSearchFilters();

            $subordinateUsers = $this->userRepository->getSubordinateUsers($masterUser, $perPage, $userSearchFilters);

            // Determine userForValidations: use master user if super_master_user, otherwise use subordinate
            $userForValidations = $masterUser->super_master_user ? $masterUser : null;

            // For each subordinate user, get their available menus (limit to 15)
            foreach ($subordinateUsers as $user) {
                $user->available_menus = $this->menuRepository->getAvailableMenusForUser($user, 15, $userForValidations, $menuSearchFilters);
            }

            return ApiResponseService::success(
                new SubordinateUserResourceCollection($subordinateUsers),
                'Subordinate users retrieved successfully'
            );

        } catch (Exception $e) {
            return ApiResponseService::unprocessableEntity('error', [
                'message' => [$e->getMessage()],
            ]);
        }
    }
}