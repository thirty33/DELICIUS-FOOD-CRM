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

        // Use 'web' guard explicitly for login attempt (supports session-based authentication)
        // This is required because the default guard may be 'sanctum' which doesn't support attempt()
        if (auth('web')->attempt([
            'email' => $identifier,
            'password' => data_get($credentials, 'password'),
        ])) {

            $user = auth('web')->user();

            // Load branch relationship to access fantasy_name
            $user->load('branch');

            // Revoke all previous tokens to ensure only one active session per user
            // This prevents users from having multiple concurrent sessions
            $user->tokens()->delete();

            $token = $user->createToken(data_get($credentials, 'device_name'))
                ->plainTextToken;

            return ApiResponseService::success([
                'token' => $token,
                'token_type' => 'bearer',
                'role' => optional($user->roles->first())->name ?? null,
                'permission' => optional($user->permissions->first())->name ?? null,
                'master_user' => $user->master_user ?? false,
                'super_master_user' => $user->super_master_user ?? false,
                'nickname' => $user->nickname ?? '',
                'name' => $user->name ?? '',
                'branch_fantasy_name' => optional($user->branch)->fantasy_name ?? null,
            ]);
        }

        // Try login with nickname as fallback
        if (auth('web')->attempt([
            'nickname' => $identifier,
            'password' => data_get($credentials, 'password'),
        ])) {

            $user = auth('web')->user();

            // Load branch relationship to access fantasy_name
            $user->load('branch');

            // Revoke all previous tokens to ensure only one active session per user
            // This prevents users from having multiple concurrent sessions
            $user->tokens()->delete();

            $token = $user->createToken(data_get($credentials, 'device_name'))
                ->plainTextToken;

            return ApiResponseService::success([
                'token' => $token,
                'token_type' => 'bearer',
                'role' => optional($user->roles->first())->name ?? null,
                'permission' => optional($user->permissions->first())->name ?? null,
                'master_user' => $user->master_user ?? false,
                'super_master_user' => $user->super_master_user ?? false,
                'nickname' => $user->nickname ?? '',
                'name' => $user->name ?? '',
                'branch_fantasy_name' => optional($user->branch)->fantasy_name ?? null,
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
