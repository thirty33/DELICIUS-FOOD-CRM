<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\WebRegistration\StoreWebRegistrationRequest;
use App\Models\WebRegistrationRequest;
use App\Services\API\V1\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Exception;

class WebRegistrationController extends Controller
{
    /**
     * Store a new web registration request.
     */
    public function store(StoreWebRegistrationRequest $request): JsonResponse
    {
        try {
            WebRegistrationRequest::create($request->validated());

            return ApiResponseService::success(
                null,
                'Solicitud de registro creada exitosamente',
                201
            );
        } catch (Exception) {
            return ApiResponseService::unprocessableEntity('error', [
                'message' => ['Error al procesar la solicitud de registro.'],
            ]);
        }
    }
}
