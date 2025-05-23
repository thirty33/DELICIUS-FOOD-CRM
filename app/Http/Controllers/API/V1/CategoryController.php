<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\Category\CategoryMenuRequest;
use Illuminate\Http\Request;
use App\Services\API\V1\ApiResponseService;
use App\Http\Resources\API\V1\CategoryResource;
use App\Http\Resources\API\V1\CategoryMenuResource;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Menu;
use App\Models\PriceListLine;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use App\Enums\Weekday;
use Illuminate\Support\Facades\Log;

class CategoryController extends Controller
{
    public function index(CategoryMenuRequest $request, Menu $menu): JsonResponse
    {
        $request = $request->validated();
        $user = auth()->user();

        $publicationDate = Carbon::parse($menu->publication_date);
        $weekday = ucfirst(strtolower($publicationDate->isoFormat('dddd')));

        $weekdayInEnglish = Weekday::fromSpanish($weekday);

        Log::info("Weekday value before conversion:", [
            'menu->publication_date' => $menu->publication_date,
            '$publicationDate' => $publicationDate,
            'weekday' => $weekday,
            '$weekdayInEnglish' => $weekdayInEnglish
        ]);

        $query = CategoryMenu::with([
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
        ])
            ->whereIn(
                'category_id',
                Product::whereIn(
                    'id',
                    PriceListLine::where('price_list_id', $user->company->price_list_id)
                        ->where('active', true)  // Añadido filtro para PriceListLine activas
                        ->select('product_id')
                )
                    ->select('category_id')
            )
            ->where('menu_id', $menu->id)
            ->orderBy('category_menu.display_order', 'asc')
            ->paginate(5);

        return ApiResponseService::success(
            CategoryMenuResource::collection($query)->additional([
                'publication_date' => $publicationDate->toDateTimeString(),
            ])->resource,
            'Categories retrieved successfully',
        );
    }
}
