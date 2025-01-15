<?php

namespace App\Http\Controllers\API\V1;

use App\Classes\UserPermissions;
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
use App\Http\Requests\API\V1\Order\UpdateStatusRequest;
use App\Models\Menu;
use App\Models\User;

class OrderController extends Controller
{
    public function show(OrderRequest $request, string $date): JsonResponse
    {
        $date = data_get($request->validated(), 'date', '');
        $carbonDate = Carbon::parse($date);
        $day = $carbonDate->day;
        $month = $carbonDate->month;
        $year = $carbonDate->year;

        $order = Order::with('orderLines.product')
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

        $date = data_get($request->validated(), 'date', '');

        $carbonDate = Carbon::parse($date);
        $user = $request->user();

        $order = $this->getOrder($user->id, $carbonDate);
        
        if (!$order) {

            $order = new Order();
            $order->user_id = $user->id;
            $order->dispatch_date = $carbonDate;
            $order->status = 'pending';
            $order->save();
        }

        $companyPriceListId = $user->company->price_list_id;

        foreach ($request->order_lines as $orderLineData) {

            $productId = $orderLineData['id'];
            $quantity = $orderLineData['quantity'];

            $productInPriceList = PriceListLine::where('price_list_id', $companyPriceListId)
                ->where('product_id', $productId);

            if (!$productInPriceList->exists()) {
                continue;
            }

            $existingOrderLine = $order->orderLines()->where('product_id', $productId)->first();

            if ($existingOrderLine) {
                $existingOrderLine->quantity = $quantity;
                $existingOrderLine->save();
            } else {
                $order->orderLines()->create([
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $productInPriceList->first()->unit_price,
                ]);
            }
        }

        return ApiResponseService::success(
            new OrderResource($this->getOrder($user->id, $carbonDate)),
            'Order updated successfully',
        );
    }

    public function delete(CreateOrUpdateOrderRequest $request, string $date): JsonResponse
    {
        $date = data_get($request->validated(), 'date', '');

        $carbonDate = Carbon::parse($date);

        $user = $request->user();

        $order = $this->getOrder($user->id, $carbonDate);

        if (!$order) {
            return ApiResponseService::notFound('Order not found');
        }

        foreach ($request->order_lines as $orderLineData) {
            $productId = $orderLineData['id'];

            $existingOrderLine = $order->orderLines()->where('product_id', $productId)->first();

            if ($existingOrderLine) {
                $existingOrderLine->delete();
            }
        }

        return ApiResponseService::success(
            new OrderResource($this->getOrder($user->id, $carbonDate)),
            'Order lines deleted successfully',
        );
    }

    private function getCurrentMenu($user, $date) {
        $menuExists = Menu::where('publication_date', $date)
        ->where('role_id', UserPermissions::getRole($user)->id)
        ->where('permissions_id', UserPermissions::getPermission($user)->id)
        ->exists();
    }

    public function updateOrderStatus(UpdateStatusRequest $request, string $date): JsonResponse
    {
        $date = data_get($request->all(), 'date', '');

        $carbonDate = Carbon::parse($date);

        $user = $request->user();

        $order = $this->getOrder($user->id, $carbonDate);

        if (
            UserPermissions::IsAgreementConsolidated($user)
            || UserPermissions::IsAgreementIndividual($user)
        ) {

            dd([
                'order' => $order->id,
                'status' => $request->status,
                'date' => $date
            ]);
        }

        if (!$order) {
            return ApiResponseService::notFound('Order not found');
        }

        $order->status = $request->status;
        $order->save();

        return ApiResponseService::success(
            new OrderResource($this->getOrder($user->id, $carbonDate)),
            'Order status updated successfully',
        );
    }
}
