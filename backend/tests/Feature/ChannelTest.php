<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use App\Models\WebchatChannelSettings;
use App\Models\WebchatSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChannelTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $agentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin Channels',
            'email' => 'admin-channels@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->agentUser = User::create([
            'name' => 'Agent Channels',
            'email' => 'agent-channels@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $this->agentUser->roles()->attach(Role::where('name', 'agent')->first());
    }

    public function test_rejects_deleting_active_channel(): void
    {
        $channel = Channel::create([
            'name' => 'Canal Activo de Prueba',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/channels/{$channel->id}");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'No se puede eliminar un canal activo. Desactívalo primero.',
            ]);

        $this->assertDatabaseHas('channels', ['id' => $channel->id]);
    }

    public function test_warns_with_conversations_count_when_deleting_without_force(): void
    {
        $channel = Channel::create([
            'name' => 'Canal con Conversaciones',
            'type' => ChannelType::WEBCHAT,
            'is_active' => false,
        ]);

        $contact = Contact::create([
            'first_name' => 'Juan',
            'last_name' => 'Perez',
        ]);

        Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'bot',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/channels/{$channel->id}");

        $response->assertStatus(422)
            ->assertJson([
                'requires_force' => true,
                'conversations_count' => 1,
            ]);

        $this->assertDatabaseHas('channels', ['id' => $channel->id]);
    }

    public function test_deletes_channel_with_conversations_when_force_is_true(): void
    {
        $channel = Channel::create([
            'name' => 'Canal Inactivo a Forzar',
            'type' => ChannelType::WEBCHAT,
            'is_active' => false,
        ]);

        $contact = Contact::create([
            'first_name' => 'Carlos',
            'last_name' => 'Gomez',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'bot',
        ]);

        $settings = WebchatChannelSettings::create([
            'channel_id' => $channel->id,
            'public_key' => 'wb_test_force_key',
            'display_name' => 'Test Widget',
            'allowed_origins' => ['http://localhost:3000'],
            'accent_color' => '#2563EB',
            'launcher_position' => 'bottom-right',
        ]);

        $session = WebchatSession::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'token_hash' => hash('sha256', 'visitor_token_123'),
            'origin' => 'http://localhost:3000',
            'last_seen_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/channels/{$channel->id}?force=true");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Canal eliminado exitosamente.',
            ]);

        $this->assertDatabaseMissing('channels', ['id' => $channel->id]);
        $this->assertDatabaseMissing('webchat_channel_settings', ['id' => $settings->id]);
        $this->assertDatabaseMissing('webchat_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'channel.deleted',
            'entity_id' => (string) $channel->id,
        ]);
    }

    public function test_deletes_inactive_channel_without_conversations_cleanly(): void
    {
        $channel = Channel::create([
            'name' => 'Canal Inactivo Limpio',
            'type' => ChannelType::WEBCHAT,
            'is_active' => false,
        ]);

        $settings = WebchatChannelSettings::create([
            'channel_id' => $channel->id,
            'public_key' => 'wb_clean_delete_key',
            'display_name' => 'Clean Widget',
            'allowed_origins' => ['http://localhost:3000'],
            'accent_color' => '#2563EB',
            'launcher_position' => 'bottom-right',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/channels/{$channel->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Canal eliminado exitosamente.',
            ]);

        $this->assertDatabaseMissing('channels', ['id' => $channel->id]);
        $this->assertDatabaseMissing('webchat_channel_settings', ['id' => $settings->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'channel.deleted',
            'entity_id' => (string) $channel->id,
        ]);
    }

    public function test_agent_without_permission_cannot_delete_channel(): void
    {
        $channel = Channel::create([
            'name' => 'Canal Prohibido',
            'type' => ChannelType::WEBCHAT,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->deleteJson("/api/v1/channels/{$channel->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('channels', ['id' => $channel->id]);
    }
}
