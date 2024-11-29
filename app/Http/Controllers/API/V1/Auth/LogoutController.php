<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Attributes\UnauthorizedResponseAttribute;
use App\Contracts\API\Auth\AuthServiceInterface;
use App\Http\Controllers\API\V1\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes\JsonContent;
use OpenApi\Attributes\Post;
use Symfony\Component\HttpFoundation\Response;

class LogoutController extends Controller
{
    public function __construct(private readonly AuthServiceInterface $authService)
    {
        parent::__construct();
    }

    public function __invoke(): JsonResponse
    {
        return $this->authService->logout();
    }
}
