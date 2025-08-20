<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Category\CategoryMenuRequest;
use Illuminate\Http\Request;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\CategoryResource;
use App\Http\Resources\API\V1\CategoryMenuResource;
use App\Http\Resources\API\V1\CategoryGroupResource;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\CategoryGroup;
use App\Models\Menu;
use App\Models\PriceListLine;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use App\Enums\Weekday;
use Illuminate\Support\Facades\Log;
use App\Repositories\UserDelegationRepository;
use Illuminate\Pipeline\Pipeline;
use App\Filters\FilterValue;
use App\Enums\Filters\CategoryFilters;

class CategoryController extends Controller
{
    protected UserDelegationRepository $userDelegationRepository;
    
    public function __construct(UserDelegationRepository $userDelegationRepository)
    {
        $this->userDelegationRepository = $userDelegationRepository;
    }
    
    public function index(CategoryMenuRequest $request, Menu $menu): JsonResponse
    {
        $request = $request->validated();
        $user = $this->userDelegationRepository->getEffectiveUser(request());

        $publicationDate = Carbon::parse($menu->publication_date);
        $weekday = ucfirst(strtolower($publicationDate->isoFormat('dddd')));

        $weekdayInEnglish = Weekday::fromSpanish($weekday);
        
        // Original query with complex with statements - keeping intact
        $baseQuery = CategoryMenu::with([
            'category' => function ($query) use ($user, $weekdayInEnglish) {
                $query->whereHas('products', function ($priceListQuery) use ($user) {
                    $priceListQuery->whereHas('priceListLines', function ($subQuery) use ($user) {
                        $subQuery->where('active', true)
                            ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                $priceListQuery->where('id', $user->company->price_list_id);
                            });
                    });
                });
                $query->with(['products' => function ($query) use ($user) {
                    $query->whereHas('priceListLines', function ($subQuery) use ($user) {
                        $subQuery->where('active', true)
                            ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                $priceListQuery->where('id', $user->company->price_list_id);
                            });
                    })->with(['priceListLines' => function ($query) use ($user) {
                        $query->where('active', true)
                            ->whereHas('priceList', function ($priceListQuery) use ($user) {
                                $priceListQuery->where('id', $user->company->price_list_id);
                            });
                    }])
                        ->with(['ingredients']);
                }]);
                $query->with(['categoryLines' => function ($query) use ($weekdayInEnglish) {
                    $query->where('weekday', $weekdayInEnglish->value)->where('active', 1);
                }]);
                $query->with(['subcategories']);

                $query->with(['categoryUserLines' => function ($query) use ($weekdayInEnglish, $user) {
                    $query->where('weekday', $weekdayInEnglish->value)
                        ->where('active', 1)
                        ->where('user_id', $user->id);
                }]);
            },
            'menu',
            'products' => function ($query) use ($user) {
                $query->whereHas('priceListLines', function ($subQuery) use ($user) {
                    $subQuery->where('active', true)
                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                            $priceListQuery->where('id', $user->company->price_list_id);
                        });
                })->with(['priceListLines' => function ($query) use ($user) {
                    $query->where('active', true)
                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                            $priceListQuery->where('id', $user->company->price_list_id);
                        });
                }])->with(['ingredients']);
            }
        ]);

        // Get priority group from request (null if not provided)
        $priorityGroup = $request['priority_group'] ?? null;
        
        // Apply filters using pipeline pattern
        $filters = [
            CategoryFilters::PriceList->create(new FilterValue(['user' => $user])),
            CategoryFilters::MenuContext->create(new FilterValue(['menu' => $menu])),
            CategoryFilters::Active->create(new FilterValue(null)),
            CategoryFilters::CategoryGroupOrder->create(new FilterValue($priorityGroup)),
            CategoryFilters::Sort->create(new FilterValue(['skip_default_sort' => (bool) $priorityGroup])),
        ];

        $query = app(Pipeline::class)
            ->send($baseQuery)
            ->through($filters)
            ->thenReturn()
            ->paginate(15);

        /* COMMENTED - Original approach before pipeline pattern
        ->whereIn(
            'category_id',
            Product::whereIn(
                'id',
                PriceListLine::where('price_list_id', $user->company->price_list_id)
                    ->where('active', true)
                    ->select('product_id')
            )
                ->select('category_id')
        )
        ->where('menu_id', $menu->id)
        ->where('is_active', true)
        ->orderBy('category_menu.display_order', 'asc')
        ->paginate(15);
        */

        return ApiResponseService::success(
            CategoryMenuResource::collection($query)->additional([
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
