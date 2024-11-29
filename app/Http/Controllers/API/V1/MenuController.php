<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\MenuResource;
use App\Models\Menu;
use Illuminate\Http\JsonResponse;

class MenuController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponseService::success(
            MenuResource::collection(Menu::where('active', 1)->paginate())->resource,
            'Active menus retrieved successfully',
        );
    }

}
