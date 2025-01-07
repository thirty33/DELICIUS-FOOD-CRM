<?php

namespace App\Http\Controllers\API\V1;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\OrderResource;
use App\Models\Order;
use App\Http\Requests\API\V1\Order\OrderRequest;

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

        if(!$order) {
            return ApiResponseService::notFound('Order not found');
        }
        
        return ApiResponseService::success(
            new OrderResource($order),
            'Order retrieved successfully',
        );
    }
}
