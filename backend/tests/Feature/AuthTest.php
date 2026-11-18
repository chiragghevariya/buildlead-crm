<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user(): void
    {
        $user = User::factory()->manager()->create(['email' => 'manager@buildlead.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'manager@buildlead.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email', 'role', 'initials']])
            ->assertJsonPath('user.role', 'manager');

        $this->assertCount(1, $user->tokens);
    }

    public function test_login_is_case_insensitive_on_email(): void
    {
        User::factory()->create(['email' => 'anna.weber@buildlead.test']);

        $this->postJson('/api/auth/login', ['email' => 'Anna.Weber@BuildLead.test', 'password' => 'password'])
            ->assertOk();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'rep@buildlead.test']);

        $this->postJson('/api/auth/login', ['email' => 'rep@buildlead.test', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['email' => 'gone@buildlead.test']);

        $this->postJson('/api/auth/login', ['email' => 'gone@buildlead.test', 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_me_requires_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_unauthenticated_api_call_without_accept_header_is_401_json(): void
    {
        $this->get('/api/auth/me')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_me_returns_current_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_role_middleware_allows_and_blocks(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'role:admin,manager'])
            ->get('/api/_test/managers-only', fn () => ['ok' => true]);

        $this->actingAs(User::factory()->manager()->create(), 'sanctum')
            ->getJson('/api/_test/managers-only')->assertOk();

        $this->app['auth']->forgetGuards();

        $this->actingAs(User::factory()->sales()->create(), 'sanctum')
            ->getJson('/api/_test/managers-only')->assertForbidden();
    }
}
