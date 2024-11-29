<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Attributes\ValidationErrorResponseAttribute;
use App\Contracts\API\Auth\AuthServiceInterface;
use App\Http\Controllers\API\V1\Controller;
use App\Http\Requests\API\V1\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes\JsonContent;
use OpenApi\Attributes\Parameter;
use OpenApi\Attributes\Post;
use OpenApi\Attributes\Schema;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
    public function __construct(private readonly AuthServiceInterface $authService)
    {
        parent::__construct();
    }

    public function __invoke(LoginRequest $request): JsonResponse
    {
        return $this->authService->login($request->validated());
    }
}
