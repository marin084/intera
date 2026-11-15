<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::where('name', 'super_admin')->first());

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'is_active'],
                'roles',
                'permissions',
            ])
            ->assertJsonMissing(['token']);

        $this->assertCount(0, $user->fresh()->tokens);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'user_id' => $user->id,
        ]);
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Credenciales inválidas.']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_failed',
        ]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Usuario inactivo. Contacte al administrador.']);
    }

    public function test_login_rate_limiting_triggers_after_max_attempts(): void
    {
        RateLimiter::clear(Str::transliterate('target@example.com|127.0.0.1'));

        User::create([
            'name' => 'Test User',
            'email' => 'target@example.com',
            'password' => Hash::make('ValidPassword123!'),
            'is_active' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'target@example.com',
                'password' => 'BadPassword123!',
            ])->assertStatus(401);
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'target@example.com',
            'password' => 'BadPassword123!',
        ]);

        $response->assertStatus(429);
    }

    public function test_user_can_logout_and_revoke_token(): void
    {
        $user = User::create([
            'name' => 'Active User',
            'email' => 'user@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => true,
        ]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Sesión cerrada correctamente.']);

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->getJson('/api/v1/users')->assertStatus(401);
        $this->getJson('/api/v1/channels')->assertStatus(401);
        $this->getJson('/api/v1/flows')->assertStatus(401);
        $this->getJson('/api/v1/conversations')->assertStatus(401);
        $this->getJson('/api/v1/audit-logs')->assertStatus(401);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::create([
            'name' => 'Profile User',
            'email' => 'profile@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::where('name', 'agent')->first());

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.email', 'profile@example.com')
            ->assertJsonPath('roles.0', 'agent');
    }

    public function test_spa_login_does_not_emit_pat_token(): void
    {
        $user = User::create([
            'name' => 'Session Only User',
            'email' => 'session_only@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::where('name', 'super_admin')->first());

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'session_only@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('token'));
        $response->assertJsonMissing(['token']);
        $this->assertCount(0, $user->fresh()->tokens);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_csrf_cookie_endpoint_returns_successful_response(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');
        $response->assertStatus(204);
        $response->assertCookie('XSRF-TOKEN');
    }

    public function test_spa_login_authenticates_web_session(): void
    {
        $user = User::create([
            'name' => 'Session User',
            'email' => 'session@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::where('name', 'agent')->first());

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'session@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertStatus(200);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_spa_logout_invalidates_session_and_clears_auth(): void
    {
        $user = User::create([
            'name' => 'Session Logout User',
            'email' => 'sesslogout@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => true,
        ]);

        $this->actingAs($user, 'web');
        $this->assertAuthenticatedAs($user, 'web');

        $logoutResponse = $this->postJson('/api/v1/auth/logout');
        $logoutResponse->assertStatus(200);

        $this->assertGuest('web');

        // Subsequent request without auth must return 401
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_session_agent_is_forbidden_from_managing_users(): void
    {
        $agent = User::create([
            'name' => 'Agent Session',
            'email' => 'agent_session@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => true,
        ]);
        $agent->roles()->attach(Role::where('name', 'agent')->first());

        $this->actingAs($agent, 'web')
            ->getJson('/api/v1/users')
            ->assertStatus(403);
    }

    public function test_session_admin_can_manage_users(): void
    {
        $admin = User::create([
            'name' => 'Admin Session',
            'email' => 'admin_session@example.com',
            'password' => Hash::make('SecurePassword123!'),
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/users')
            ->assertStatus(200);
    }
}
