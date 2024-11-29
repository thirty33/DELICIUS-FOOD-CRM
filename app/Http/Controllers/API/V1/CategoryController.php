<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponseService::success(
            CategoryResource::collection(Category::paginate())->resource,
            'Categories retrieved successfully',
        );
    }
}
