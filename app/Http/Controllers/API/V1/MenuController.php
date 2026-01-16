<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Menu\DelegateUserRequest;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\MenuResource;
use App\Models\Menu;
use App\Models\Order;
use App\Repositories\UserDelegationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Pipeline\Pipeline;
use App\Filters\FilterValue;
use App\Enums\Filters\MenuFilters;

class MenuController extends Controller
{
    protected $userDelegationRepository;

    public function __construct(UserDelegationRepository $userDelegationRepository)
    {
        $this->userDelegationRepository = $userDelegationRepository;
    }

    public function index(DelegateUserRequest $request): JsonResponse
    {
        try {

            $user = $this->userDelegationRepository->getEffectiveUser($request);
            $userForValidations = $this->userDelegationRepository->getUserForValidations($request);

            $baseQuery = Menu::query();

            $filters = [
                MenuFilters::Active->create(new FilterValue(null)),
                MenuFilters::PublicationDate->create(new FilterValue(['date' => Carbon::now()->startOfDay()])),
                MenuFilters::RolePermission->create(new FilterValue(['user' => $user])),
                MenuFilters::CompanyAccess->create(new FilterValue(['user' => $user])),
                MenuFilters::LateOrders->create(new FilterValue(['user' => $userForValidations])),
                MenuFilters::WeekendDispatch->create(new FilterValue(['allow_weekends' => $user->allow_weekend_orders])),
                MenuFilters::Sort->create(new FilterValue(['field' => 'publication_date', 'direction' => 'asc'])),
            ];
    
            $menus = app(Pipeline::class)
                ->send($baseQuery)
                ->through($filters)
                ->thenReturn();
                
            $menus = $menus->addSelect([
                'has_order' => Order::selectRaw('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END')
                    ->where('user_id', $user->id)
                    ->where('status', \App\Enums\OrderStatus::PROCESSED->value)
                    ->whereRaw('DATE(dispatch_date) = DATE(menus.publication_date)')
                    ->limit(1)
            ]);
            
            $menus = $menus->paginate();
        
            return ApiResponseService::success(
                MenuResource::collection($menus)->resource,
                'Active menus retrieved successfully',
            );

        } catch (\Exception $e) {
            
            return ApiResponseService::error(
                $e->getMessage(),
                $e->getCode(),
                $e->getTraceAsString(),
            );
        }   
    }
}
