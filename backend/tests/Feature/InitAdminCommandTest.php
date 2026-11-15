<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InitAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_init_admin_creates_super_admin_with_valid_password(): void
    {
        $this->artisan('conexa:init-admin', [
            '--email' => 'admin@test.com',
            '--name' => 'Admin Test',
            '--password' => 'StrongPassword123!',
        ])
            ->expectsOutputToContain('Super Administrador [admin@test.com] configurado correctamente con éxito!')
            ->assertSuccessful();

        $user = User::where('email', 'admin@test.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('StrongPassword123!', $user->password));
        $this->assertTrue($user->hasRole('super_admin'));
    }

    public function test_init_admin_fails_when_password_less_than_12_characters(): void
    {
        $this->artisan('conexa:init-admin', [
            '--email' => 'shortpass@test.com',
            '--name' => 'Short Pass',
            '--password' => 'Short1!',
        ])
            ->expectsOutputToContain('La contraseña debe tener al menos 12 caracteres de longitud')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'shortpass@test.com']);
    }

    public function test_init_admin_fails_when_email_is_invalid(): void
    {
        $this->artisan('conexa:init-admin', [
            '--email' => 'not-an-email',
            '--name' => 'Invalid Email',
            '--password' => 'StrongPassword123!',
        ])
            ->assertFailed();
    }

    public function test_init_admin_updates_existing_user_when_confirmed(): void
    {
        $existing = User::create([
            'name' => 'Existing Normal User',
            'email' => 'existing@test.com',
            'password' => Hash::make('OldPassword1234!'),
            'is_active' => true,
        ]);
        $existing->roles()->attach(Role::where('name', 'agent')->first());

        $this->artisan('conexa:init-admin', [
            '--email' => 'existing@test.com',
            '--name' => 'Promoted Admin',
            '--password' => 'NewStrongPassword123!',
        ])
            ->expectsConfirmation('El usuario existing@test.com ya existe. ¿Desea actualizar su contraseña y asegurar rol super_admin?', 'yes')
            ->expectsOutputToContain('Super Administrador [existing@test.com] configurado correctamente con éxito!')
            ->assertSuccessful();

        $existing->refresh();
        $this->assertEquals('Promoted Admin', $existing->name);
        $this->assertTrue(Hash::check('NewStrongPassword123!', $existing->password));
        $this->assertTrue($existing->hasRole('super_admin'));
    }

    public function test_init_admin_prompts_for_password_and_confirms_interactively(): void
    {
        $this->artisan('conexa:init-admin', [
            '--email' => 'interactive@test.com',
            '--name' => 'Interactive Admin',
        ])
            ->expectsQuestion('Password:', 'InteractiveStrongPass123!')
            ->expectsQuestion('Confirm password:', 'InteractiveStrongPass123!')
            ->expectsOutputToContain('Super Administrador [interactive@test.com] configurado correctamente con éxito!')
            ->assertSuccessful();

        $user = User::where('email', 'interactive@test.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('InteractiveStrongPass123!', $user->password));
    }

    public function test_init_admin_fails_when_interactive_passwords_do_not_match(): void
    {
        $this->artisan('conexa:init-admin', [
            '--email' => 'mismatch@test.com',
            '--name' => 'Mismatch Admin',
        ])
            ->expectsQuestion('Password:', 'InteractiveStrongPass123!')
            ->expectsQuestion('Confirm password:', 'DifferentPass123!')
            ->expectsOutputToContain('Error: Las contraseñas no coinciden.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@test.com']);
    }
}
