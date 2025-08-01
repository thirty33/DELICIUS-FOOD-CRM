<?php

namespace App\Http\Controllers\API\V1;

use App\Classes\Menus\MenuHelper;
use App\Classes\OrderHelper;
use App\Classes\Orders\Validations\AtLeastOneProductByCategory;
use App\Classes\Orders\Validations\DispatchRulesCategoriesValidation;
use App\Classes\Orders\Validations\MenuExistsValidation;
use App\Classes\Orders\Validations\OneProductPerCategory;
use App\Classes\Orders\Validations\MaxOrderAmountValidation;
use App\Classes\Orders\Validations\OneProductBySubcategoryValidation;
use App\Classes\Orders\Validations\MandatoryCategoryValidation;
use App\Classes\UserPermissions;
use App\Enums\OrderStatus;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\OrderResource;
use App\Models\Order;
use App\Models\PriceListLine;
use App\Http\Requests\API\V1\Order\OrderRequest;
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

class OrderController extends Controller
{

    public function index(OrderListRequest $request): JsonResponse
    {
        $user = $request->user();

        $ordersQuery = Order::where('user_id', $user->id);

        if ($request->has('time_period')) {

            $timePeriod = $request->input('time_period');
            $now = Carbon::now();

            switch ($timePeriod) {
                case 'this_week':
                    $ordersQuery->whereBetween('dispatch_date', [$now->copy()->startOfWeek(), $now->endOfWeek()]);
                    break;
                case 'this_month':
                    $ordersQuery->whereBetween('dispatch_date', [$now->copy()->startOfMonth(), $now->endOfMonth()]);
                    break;
                case 'last_3_months':
                    $ordersQuery->whereBetween('dispatch_date', [$now->copy()->subMonths(3)->startOfMonth(), $now->endOfMonth()]);
                    break;
                case 'last_6_months':
                    $ordersQuery->whereBetween('dispatch_date', [$now->copy()->subMonths(6)->startOfMonth(), $now->endOfMonth()]);
                    break;
                case 'this_year':
                    $ordersQuery->whereBetween('dispatch_date', [$now->copy()->startOfYear(), $now->endOfYear()]);
                    break;
            }
        }

        if ($request->has('order_status')) {
            $ordersQuery->where('status', $request->input('order_status'));
        }

        $orders = $ordersQuery->orderBy('dispatch_date', 'desc')->paginate(10);

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

        $order = Order::with([
            'orderLines.product.category.subcategories',
        ])
            ->where('user_id', $request->user()->id)
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
        $order = Order::with([
            'orderLines.product.category.subcategories',
            'orderLines.product.ingredients',
        ])
            ->where('user_id', $request->user()->id)
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

            $date = data_get($request->validated(), 'date', '');

            $carbonDate = Carbon::parse($date);
            $user = $request->user();
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

            return ApiResponseService::success(
                new OrderResource($this->getOrder($user->id, $carbonDate)),
                'Order updated successfully',
            );
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

            $user = $request->user();

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

            $user = $request->user();

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


            $validationChain = new MenuExistsValidation();
            $validationChain
                ->linkWith(new DispatchRulesCategoriesValidation())
                ->linkWith(new AtLeastOneProductByCategory())
                ->linkWith(new OneProductPerCategory())
                ->linkWith(new MaxOrderAmountValidation())
                ->linkWith(new OneProductBySubcategoryValidation())
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
            $user = $request->user();
            
            // Find the order by ID and ensure it belongs to the authenticated user
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
