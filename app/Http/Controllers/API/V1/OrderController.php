<?php

namespace App\Http\Controllers\API\V1;

use App\Classes\Menus\MenuHelper;
use App\Classes\OrderHelper;
use App\Classes\Orders\Validations\AtLeastOneProductByCategory;
use App\Classes\Orders\Validations\DispatchRulesCategoriesValidation;
use App\Classes\Orders\Validations\MenuExistsValidation;
use App\Classes\Orders\Validations\MenuCompositionValidation;
use App\Classes\Orders\Validations\MaxOrderAmountValidation;
use App\Classes\Orders\Validations\SubcategoryExclusion;
use App\Classes\Orders\Validations\PolymorphicExclusion;
use App\Classes\Orders\Validations\MandatoryCategoryValidation;
use App\Classes\Orders\Validations\OneProductPerCategorySimple;
use App\Classes\Orders\Validations\OneProductPerSubcategory;
use App\Classes\Orders\Validations\ExactProductCountPerSubcategory;
use App\Classes\UserPermissions;
use App\Enums\OrderStatus;
use Carbon\Carbon;
use App\Enums\Filters\OrderFilters;
use App\Filters\FilterValue;
use Illuminate\Pipeline\Pipeline;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\OrderResource;
use App\Models\Order;
use App\Models\PriceListLine;
use App\Http\Requests\API\V1\Order\OrderRequest;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\API\V1\Order\CreateOrUpdateOrderRequest;
use App\Http\Requests\API\V1\Order\OrderByIdRequest;
use App\Http\Requests\API\V1\Order\UpdateStatusRequest;
use App\Http\Requests\API\V1\Order\OrderListRequest;
use App\Http\Requests\API\V1\Order\UpdateOrderUserCommentRequest;
use App\Http\Resources\API\V1\OrderResourceCollection;
use App\Models\Menu;
use App\Models\Product;
use App\Models\User;
use Exception;
use App\Repositories\UserDelegationRepository;

class OrderController extends Controller
{
    protected UserDelegationRepository $userDelegationRepository;
    
    public function __construct(UserDelegationRepository $userDelegationRepository)
    {
        $this->userDelegationRepository = $userDelegationRepository;
    }

    public function index(OrderListRequest $request): JsonResponse
    {
        $user = $request->user();
        
        $filters = [
            OrderFilters::Company->create(new FilterValue([
                'user' => $user,
                'master_user' => $user->master_user,
                'company_id' => $user->company_id
            ])),
            OrderFilters::User->create(new FilterValue($user->master_user ? null : $user->id)),
            OrderFilters::TimePeriod->create(new FilterValue($request->input('time_period'))),
            OrderFilters::OrderStatus->create(new FilterValue($request->input('order_status'))),
            OrderFilters::UserSearch->create(new FilterValue($request->input('user_search'))),
            OrderFilters::BranchSearch->create(new FilterValue($request->input('branch_search'))),
            OrderFilters::Sort->create(new FilterValue([
                'column' => $request->input('sort_column', 'dispatch_date'),
                'direction' => $request->input('sort_direction', 'desc')
            ])),
        ];

        $ordersQuery = app(Pipeline::class)
            ->send(Order::query()->with(['user.branch']))
            ->through($filters)
            ->thenReturn();

        $perPage = $request->input('per_page', 10);
        $orders = $ordersQuery->paginate($perPage);

        return ApiResponseService::success(
            (new OrderResourceCollection($orders))->withMenu(),
            'Orders retrieved successfully',
        );
    }

    public function show(OrderRequest $request, string $date): JsonResponse
    {
        $date = data_get($request->validated(), 'date', '');
        $carbonDate = Carbon::parse($date);
        $day = $carbonDate->day;
        $month = $carbonDate->month;
        $year = $carbonDate->year;
        
        $user = $this->userDelegationRepository->getEffectiveUser($request);

        $order = Order::with([
            'orderLines.product.category.subcategories',
        ])
            ->where('user_id', $user->id)
            ->whereDay('dispatch_date', '=', $day)
            ->whereMonth('dispatch_date', '=', $month)
            ->whereYear('dispatch_date', '=', $year)
            ->first();

        if (!$order) {
            return ApiResponseService::notFound('Order not found');
        }

        return ApiResponseService::success(
            new OrderResource($order),
            'Order retrieved successfully',
        );
    }

    public function showById(OrderByIdRequest $request, string $id): JsonResponse
    {
        $user = $this->userDelegationRepository->getEffectiveUser($request);
        
        $order = Order::with([
            'orderLines.product.category.subcategories',
            'orderLines.product.ingredients',
        ])
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return ApiResponseService::notFound('Order not found');
        }

        return ApiResponseService::success(
            new OrderResource($order),
            'Order retrieved successfully',
        );
    }

    private function getOrder($userId, $carbonDate)
    {
        $day = $carbonDate->day;
        $month = $carbonDate->month;
        $year = $carbonDate->year;

        return Order::with('orderLines.product')
            ->where('user_id', $userId)
            ->whereDay('dispatch_date', '=', $day)
            ->whereMonth('dispatch_date', '=', $month)
            ->whereYear('dispatch_date', '=', $year)
            ->first();
    }

    public function update(CreateOrUpdateOrderRequest $request, string $date): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $date) {
                $date = data_get($request->validated(), 'date', '');

                $carbonDate = Carbon::parse($date);
                $user = $this->userDelegationRepository->getEffectiveUser($request);
                $orderIsPartiallyScheduled = false;

                foreach ($request->order_lines as $orderLineData) {
                    if (isset($orderLineData['partially_scheduled']) && $orderLineData['partially_scheduled']) {

                        $orderIsPartiallyScheduled = true;

                        if (!UserPermissions::IsCafe($user)) {
                            throw new Exception('User does not have the required CAFE role.');
                        }
                    }
                }

                $order = $this->getOrder($user->id, $carbonDate);

                if ($order && $order->status === OrderStatus::PROCESSED->value) {
                    throw new Exception('La orden ya ha sido procesada');
                }

                if (!$order) {
                    $order = new Order();
                    $order->user_id = $user->id;
                    $order->dispatch_date = $carbonDate;
                    $order->save();
                }

                $validationChain = new MenuExistsValidation();

                $validationChain
                    ->validate($order, $user, $carbonDate);

                $companyPriceListId = $user->company->price_list_id;

                foreach ($request->order_lines as $orderLineData) {

                    $productId = $orderLineData['id'];
                    $quantity = $orderLineData['quantity'];
                    $partiallyScheduled = $orderLineData['partially_scheduled'] ?? false;

                    $existingOrderLine = $order->orderLines()->where('product_id', $productId)->first();

                    // Validar reglas de categoría si partially_scheduled es true
                    if ($partiallyScheduled || ($existingOrderLine && $existingOrderLine->partially_scheduled)) {
                        $product = Product::find($productId);
                        if ($product) {
                            OrderHelper::validateCategoryLineRulesForProduct($product, $carbonDate, $user);
                        }
                    }

                    // Verificar si el producto está en la lista de precios
                    $productInPriceList = PriceListLine::where('price_list_id', $companyPriceListId)
                        ->where('product_id', $productId);

                    if (!$productInPriceList->exists()) {
                        continue;
                    }

                    $order->orderLines()->updateOrCreate(
                        ['product_id' => $productId],
                        [
                            'quantity' => $quantity,
                            'unit_price' => $productInPriceList->first()->unit_price,
                            'partially_scheduled' => $partiallyScheduled
                        ]
                    );
                }

                $orderIsAlreadyStatus = $order->status === OrderStatus::PARTIALLY_SCHEDULED->value;
                $order->status = $orderIsAlreadyStatus || $orderIsPartiallyScheduled ? OrderStatus::PARTIALLY_SCHEDULED->value : OrderStatus::PENDING->value;

                if (!$order->orderLines()->where('partially_scheduled', true)->exists()) {
                    $order->status = OrderStatus::PENDING->value;
                }

                $order->save();

                // Validate order composition after order lines are created/updated
                // Refresh order to get updated order lines
                $order = $order->fresh(['orderLines.product.category.subcategories']);

                $validationChain2 = new DispatchRulesCategoriesValidation();
                $validationChain2
                    ->linkWith(new OneProductPerCategorySimple())
                    ->linkWith(new OneProductPerSubcategory())
                    ->linkWith(new SubcategoryExclusion())
                    ->linkWith(new PolymorphicExclusion()); // NEW: Validates polymorphic exclusions for Consolidated agreements

                $validationChain2
                    ->validate($order, $user, $carbonDate);

                return ApiResponseService::success(
                    new OrderResource($this->getOrder($user->id, $carbonDate)),
                    'Order updated successfully',
                );
            });
        } catch (Exception $e) {
            return ApiResponseService::unprocessableEntity('error', [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    public function delete(CreateOrUpdateOrderRequest $request, string $date): JsonResponse
    {
        try {

            $date = data_get($request->validated(), 'date', '');

            $carbonDate = Carbon::parse($date);

            $user = $this->userDelegationRepository->getEffectiveUser($request);

            $order = $this->getOrder($user->id, $carbonDate);

            if (!$order) {
                return ApiResponseService::notFound('Order not found');
            }

            if ($order->status == OrderStatus::PROCESSED->value) {
                throw new Exception("La orden ya ha sido procesada");
            }

            $validationChain = new MenuExistsValidation();

            $validationChain
                ->validate($order, $user, $carbonDate);

            foreach ($request->order_lines as $orderLineData) {
                $productId = $orderLineData['id'];

                $existingOrderLine = $order->orderLines()->where('product_id', $productId)->first();

                if ($existingOrderLine) {

                    if ($existingOrderLine->partially_scheduled) {
                        OrderHelper::validateCategoryLineRulesForProduct($existingOrderLine->product, $carbonDate, $user);
                    }

                    $existingOrderLine->delete();
                }
            }

            // Verificar si la orden no tiene orderLines después de eliminar
            if ($order->orderLines()->count() === 0) {
                $order->status = OrderStatus::PENDING->value;
                $order->save();
            }

            return ApiResponseService::success(
                new OrderResource($this->getOrder($user->id, $carbonDate)),
                'Order lines deleted successfully',
            );
        } catch (Exception $e) {
            return ApiResponseService::unprocessableEntity('error', [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    public function updateOrderStatus(UpdateStatusRequest $request, string $date): JsonResponse
    {
        try {

            $date = data_get($request->all(), 'date', '');

            $carbonDate = Carbon::parse($date);

            $user = $this->userDelegationRepository->getEffectiveUser($request);

            $order = $this->getOrder($user->id, $carbonDate);

            if (!$order) {
                throw new Exception("No existe un pedido para esta fecha");
            }

            if ($request->status != OrderStatus::PROCESSED->value) {
                throw new Exception("estado no válido");
            }

            if ($order->status == OrderStatus::PROCESSED->value) {
                throw new Exception("La orden ya ha sido procesada");
            }

            if ($order->status == OrderStatus::CANCELED->value) {
                throw new Exception("No se puede procesar una orden cancelada");
            }


            $validationChain = new MenuExistsValidation();
            $validationChain
                ->linkWith(new DispatchRulesCategoriesValidation())
                ->linkWith(new AtLeastOneProductByCategory())
                ->linkWith(new MaxOrderAmountValidation())
                ->linkWith(new SubcategoryExclusion())
                ->linkWith(new MenuCompositionValidation())
                ->linkWith(new MandatoryCategoryValidation())
                ->linkWith(new ExactProductCountPerSubcategory());

            $validationChain
                ->validate($order, $user, $carbonDate);

            if (!$order) {
                return ApiResponseService::notFound('Order not found');
            }

            $order->status = $request->status;
            $order->save();

            return ApiResponseService::success(
                new OrderResource($this->getOrder($user->id, $carbonDate)),
                'Order status updated successfully',
            );
        } catch (Exception $e) {
            return ApiResponseService::unprocessableEntity('error', [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    public function partiallyScheduleOrder(UpdateStatusRequest $request, string $date): JsonResponse
    {
        try {

            $date = data_get($request->all(), 'date', '');

            $carbonDate = Carbon::parse($date);

            $user = $request->user();

            $order = $this->getOrder($user->id, $carbonDate);

            if (!$order) {
                throw new Exception("No existe un pedido para esta fecha");
            }

            if ($request->status != OrderStatus::PARTIALLY_SCHEDULED->value) {
                throw new Exception("estado no válido");
            }

            if ($order->status == OrderStatus::PARTIALLY_SCHEDULED->value) {
                throw new Exception("La orden ya ha sido procesada");
            }


            $validationChain = new MenuExistsValidation();
            $validationChain
                ->linkWith(new DispatchRulesCategoriesValidation())
                ->linkWith(new MandatoryCategoryValidation());

            $validationChain
                ->validate($order, $user, $carbonDate);

            if (!$order) {
                return ApiResponseService::notFound('Order not found');
            }

            $order->status = $request->status;
            $order->save();

            return ApiResponseService::success(
                new OrderResource($this->getOrder($user->id, $carbonDate)),
                'Order status updated successfully',
            );
        } catch (Exception $e) {
            return ApiResponseService::unprocessableEntity('error', [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    public function updateUserComment(UpdateOrderUserCommentRequest $request, string $id): JsonResponse
    {
        try {
            $user = $this->userDelegationRepository->getEffectiveUser($request);

            // Find the order by ID and ensure it belongs to the effective user
            $order = Order::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return ApiResponseService::notFound('Order not found');
            }

            // Update the user comment
            $order->user_comment = $request->validated()['user_comment'];
            $order->save();

            return ApiResponseService::success(
                new OrderResource($order),
                'Order user comment updated successfully'
            );
        } catch (Exception $e) {
            return ApiResponseService::unprocessableEntity('error', [
                'message' => [$e->getMessage()],
            ]);
        }
    }
}
