<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponseService::success(
            ProductResource::collection(Product::paginate())->resource,
            'Products retrieved successfully',
        );
    }
}