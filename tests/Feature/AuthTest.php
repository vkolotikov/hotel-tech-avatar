<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_for_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'phase0@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'phase0@example.com',
            'password' => 'secret123',
            'device_name' => 'pixel-test',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.email', 'phase0@example.com');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'pixel-test',
        ]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'phase0@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'phase0@example.com',
            'password' => 'wrong',
            'device_name' => 'pixel-test',
        ])->assertStatus(422);
    }

    public function test_login_requires_device_name(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'phase0@example.com',
            'password' => 'secret123',
        ])->assertStatus(422);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create(['email' => 'phase0@example.com']);
        $token = $user->createToken('pixel-test')->plainTextToken;

        $this->getJson('/api/v1/me', [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertOk()
            ->assertJson([
                'id' => $user->id,
                'email' => 'phase0@example.com',
            ]);
    }

    public function test_me_rejects_unauthenticated_request(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('pixel-test')->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Force fresh auth resolution — the test client caches the resolved
        // user on the auth manager within a single test, so a second request
        // here would otherwise see the already-authenticated user.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me', [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(401);
    }
}
