<?php

namespace App\Services\API\V1;

use App\Contracts\API\Auth\AuthServiceInterface;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AuthSanctumService implements AuthServiceInterface
{
    public function login(array $credentials): JsonResponse
    {
        $identifier = data_get($credentials, 'email');

        if (!$identifier) {
            return ApiResponseService::unauthorized('Email or nickname is required');
        }

        if (auth()->attempt([
            'email' => $identifier,
            'password' => data_get($credentials, 'password'),
        ])) {

            $token = auth()
                ->user()
                ->createToken(data_get($credentials, 'device_name'))
                ->plainTextToken;

            return ApiResponseService::success([
                'token' => $token,
                'token_type' => 'bearer',
                'role' => optional(auth()->user()->roles->first())->name ?? null,
                'permission' => optional(auth()->user()->permissions->first())->name ?? null,
            ]);
        }

        if (auth()->attempt([
            'nickname' => $identifier,
            'password' => data_get($credentials, 'password'),
        ])) {

            $token = auth()
                ->user()
                ->createToken(data_get($credentials, 'device_name'))
                ->plainTextToken;

            return ApiResponseService::success([
                'token' => $token,
                'token_type' => 'bearer',
                'role' => optional(auth()->user()->roles->first())->name ?? null,
                'permission' => optional(auth()->user()->permissions->first())->name ?? null,
            ]);
        }

        return ApiResponseService::unauthorized();
    }

    public function register(array $data): JsonResponse
    {
        $user = User::create($data);

        $token = $user->createToken(data_get($data, 'device_name'))->plainTextToken;

        return ApiResponseService::success([
            'token' => $token,
            'token_type' => 'bearer',
        ]);
    }

    public function logout(): JsonResponse
    {
        auth()->user()->tokens()->delete();

        return ApiResponseService::success(null, 'Successfully logged out');
    }
}
