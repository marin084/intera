<?php

namespace Tests\Feature;

use App\Models\CannedResponse;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CannedResponseTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supervisor;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        CannedResponse::query()->delete();

        $adminRole = Role::where('name', 'super_admin')->first();
        $supervisorRole = Role::where('name', 'supervisor')->first();
        $agentRole = Role::where('name', 'agent')->first();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => Hash::make('password123456'),
            'is_active' => true,
        ]);
        $this->admin->roles()->attach($adminRole);

        $this->supervisor = User::create([
            'name' => 'Supervisor User',
            'email' => 'supervisor@test.com',
            'password' => Hash::make('password123456'),
            'is_active' => true,
        ]);
        $this->supervisor->roles()->attach($supervisorRole);

        $this->agent = User::create([
            'name' => 'Agent User',
            'email' => 'agent@test.com',
            'password' => Hash::make('password123456'),
            'is_active' => true,
        ]);
        $this->agent->roles()->attach($agentRole);
    }

    public function test_canned_responses_listing_and_search(): void
    {
        CannedResponse::create([
            'title' => 'Cuentas Banco de Costa Rica',
            'shortcut' => 'bcr',
            'content' => 'IBAN CR24015201001000002303',
            'category' => 'Finanzas',
            'is_active' => true,
        ]);

        CannedResponse::create([
            'title' => 'Canal de YouTube',
            'shortcut' => 'youtube',
            'content' => 'https://youtube.com/@CanalOficial',
            'category' => 'Atención',
            'is_active' => true,
        ]);

        CannedResponse::create([
            'title' => 'Respuesta Inactiva',
            'shortcut' => 'inactiva',
            'content' => 'Texto inactivo',
            'category' => 'Pruebas',
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->agent);

        // List active
        $response = $this->getJson('/api/v1/canned-responses');
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['shortcut' => 'bcr', 'formatted_shortcut' => '/bcr'])
            ->assertJsonFragment(['shortcut' => 'youtube', 'formatted_shortcut' => '/youtube'])
            ->assertJsonMissing(['shortcut' => 'inactiva']);

        // Search by shortcut with leading slash
        $searchResponse = $this->getJson('/api/v1/canned-responses?search=/bcr');
        $searchResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['shortcut' => 'bcr']);

        // Search by content text
        $contentSearch = $this->getJson('/api/v1/canned-responses?search=YouTube');
        $contentSearch->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['shortcut' => 'youtube']);
    }

    public function test_agent_can_list_but_cannot_create_canned_responses(): void
    {
        Sanctum::actingAs($this->agent);

        $payload = [
            'title' => 'Nueva respuesta',
            'shortcut' => 'prueba',
            'content' => 'Texto de prueba',
        ];

        $response = $this->postJson('/api/v1/canned-responses', $payload);
        $response->assertForbidden();

        $this->assertDatabaseMissing('canned_responses', [
            'shortcut' => 'prueba',
        ]);
    }

    public function test_admin_and_supervisor_can_create_update_and_delete_canned_responses(): void
    {
        Sanctum::actingAs($this->supervisor);

        // Create
        $createResponse = $this->postJson('/api/v1/canned-responses', [
            'title' => 'Datos Bancolombia Nequi',
            'shortcut' => 'nequi',
            'content' => 'Cuenta de Ahorros 123-456789-01',
            'category' => 'Finanzas',
        ]);

        $createResponse->assertCreated()
            ->assertJsonFragment([
                'title' => 'Datos Bancolombia Nequi',
                'shortcut' => 'nequi',
                'formatted_shortcut' => '/nequi',
            ]);

        $cannedId = $createResponse->json('data.id');

        // Update
        $updateResponse = $this->putJson("/api/v1/canned-responses/{$cannedId}", [
            'title' => 'Datos Bancolombia Nequi Actualizado',
            'content' => 'Cuenta actualizada 987-654321-00',
        ]);

        $updateResponse->assertOk()
            ->assertJsonFragment([
                'title' => 'Datos Bancolombia Nequi Actualizado',
                'content' => 'Cuenta actualizada 987-654321-00',
            ]);

        // Delete as Admin
        Sanctum::actingAs($this->admin);
        $deleteResponse = $this->deleteJson("/api/v1/canned-responses/{$cannedId}");
        $deleteResponse->assertOk();

        $this->assertDatabaseMissing('canned_responses', ['id' => $cannedId]);
    }

    public function test_canned_response_shortcut_validations(): void
    {
        Sanctum::actingAs($this->admin);

        // Invalid shortcut with spaces or special characters
        $invalidShortcut = $this->postJson('/api/v1/canned-responses', [
            'title' => 'Atajo Inválido',
            'shortcut' => 'atajo con espacios!',
            'content' => 'Contenido',
        ]);

        $invalidShortcut->assertUnprocessable()
            ->assertJsonValidationErrors(['shortcut']);

        // First creation succeeds
        $valid1 = $this->postJson('/api/v1/canned-responses', [
            'title' => 'Atajo Único',
            'shortcut' => 'unico',
            'content' => 'Contenido 1',
        ]);
        $valid1->assertCreated();

        // Duplicate shortcut fails
        $duplicate = $this->postJson('/api/v1/canned-responses', [
            'title' => 'Atajo Duplicado',
            'shortcut' => 'unico',
            'content' => 'Contenido 2',
        ]);
        $duplicate->assertUnprocessable()
            ->assertJsonValidationErrors(['shortcut']);
    }
}
