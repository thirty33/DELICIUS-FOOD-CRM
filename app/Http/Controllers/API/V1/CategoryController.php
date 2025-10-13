<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Category\CategoryMenuRequest;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\CategoryMenuResource;
use App\Http\Resources\API\V1\CategoryGroupResource;
use App\Models\CategoryGroup;
use App\Models\Menu;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use App\Repositories\UserDelegationRepository;
use App\Repositories\CategoryMenuRepository;

class CategoryController extends Controller
{
    protected UserDelegationRepository $userDelegationRepository;
    protected CategoryMenuRepository $categoryMenuRepository;

    public function __construct(
        UserDelegationRepository $userDelegationRepository,
        CategoryMenuRepository $categoryMenuRepository
    ) {
        $this->userDelegationRepository = $userDelegationRepository;
        $this->categoryMenuRepository = $categoryMenuRepository;
    }
    
    public function index(CategoryMenuRequest $request, Menu $menu): JsonResponse
    {
        $request = $request->validated();
        $user = $this->userDelegationRepository->getEffectiveUser(request());

        $publicationDate = Carbon::parse($menu->publication_date);
        $priorityGroup = $request['priority_group'] ?? null;

        // Get category menus using repository
        $categoryMenus = $this->categoryMenuRepository->getCategoryMenusForUser(
            $menu,
            $user,
            $priorityGroup,
            15
        );

        return ApiResponseService::success(
            CategoryMenuResource::collection($categoryMenus)->additional([
                'publication_date' => $publicationDate->toDateTimeString(),
            ])->resource,
            'Categories retrieved successfully',
        );
    }

    public function categoryGroups(CategoryMenuRequest $request, Menu $menu): JsonResponse
    {
        $request = $request->validated();
        $user = $this->userDelegationRepository->getEffectiveUser(request());

        // Simple query to get category groups associated with categories 
        // that are in the menu and have products with price list lines for the user's company
        $categoryGroups = CategoryGroup::whereHas('categories', function ($query) use ($menu, $user) {
            $query->whereHas('menus', function ($menuQuery) use ($menu) {
                $menuQuery->where('menus.id', $menu->id)
                    ->where('category_menu.is_active', true);
            })
            ->whereHas('products', function ($productQuery) use ($user) {
                $productQuery->whereHas('priceListLines', function ($priceListQuery) use ($user) {
                    $priceListQuery->where('active', true)
                        ->whereHas('priceList', function ($priceListSubQuery) use ($user) {
                            $priceListSubQuery->where('id', $user->company->price_list_id);
                        });
                });
            });
        })
        ->distinct()
        ->get();

        return ApiResponseService::success(
            CategoryGroupResource::collection($categoryGroups),
            'Category groups retrieved successfully',
        );
    }
}
