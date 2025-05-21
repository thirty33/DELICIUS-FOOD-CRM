<?php

namespace Tests\Feature\API\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\User;

#[Group('api:v1')]
#[Group('api:v1:auth')]
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_unauthenticated_user_cannot_access(): void
    {
        $this->getJson(route('v1.menus.index'))
            ->assertUnauthorized();
    }

    #[Test]
    public function an_user_can_login(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(route('v1.login'), [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test'
        ])
            ->assertOk();

        $this->assertArrayHasKey('token', $response->json('data'));
        $this->assertArrayHasKey('token_type', $response->json('data'));
    }

    #[Test]
    public function an_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson(route('v1.logout'))
            ->assertOk();

        $this->assertEmpty($user->tokens);
    }

    /**
     * Test that login throttling works and returns 429 after exceeding the limit
     * 
     * The route is configured to allow 10 requests per minute
     */
    #[Test]
    public function login_throttling_returns_too_many_requests_after_limit_exceeded(): void
    {
        // Create a test user
        $user = User::factory()->create();
        $loginData = [
            'email' => $user->email,
            'password' => 'wrong_password',
            'device_name' => 'test'
        ];

        // First, exhaust the rate limit (10 allowed attempts according to the route)
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('v1.login'), $loginData);
        }

        // The next attempt should return "Too Many Requests" (429 status code)
        $this->postJson(route('v1.login'), $loginData)
            ->assertStatus(429)
            ->assertJsonStructure(['message']);
    }
}
