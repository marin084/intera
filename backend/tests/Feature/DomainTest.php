<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => Hash::make('TestSecuredPass@2026'),
            'is_active' => true,
        ]);
        $this->adminUser->roles()->attach(Role::where('name', 'super_admin')->first());
    }

    public function test_can_create_webchat_channel(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/channels', [
                'name' => 'Principal Webchat',
                'type' => 'webchat',
                'configuration' => [
                    'theme_color' => '#2563eb',
                    'welcome_message' => '¡Hola! ¿En qué podemos ayudarte?',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Principal Webchat')
            ->assertJsonPath('data.type', 'webchat');

        $this->assertDatabaseHas('channels', [
            'name' => 'Principal Webchat',
            'type' => 'webchat',
        ]);
    }

    public function test_cannot_create_unsupported_channel_in_phase_1(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/channels', [
                'name' => 'Messenger Test',
                'type' => 'facebook_messenger',
            ]);

        $response->assertStatus(422);
    }

    public function test_conversation_and_messaging_domain(): void
    {
        $channel = Channel::create([
            'name' => 'Webchat Test',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
        ]);

        $contact = Contact::create([
            'display_name' => 'Juan Pérez',
            'external_identifier' => 'client_123',
            'email' => 'juan@example.com',
            'phone' => '+1234567890',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
            'started_at' => now(),
        ]);

        $inboundMessage = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => 'Hola, necesito ayuda con mi pedido.',
            'sent_at' => now(),
        ]);

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'status' => 'bot']);
        $this->assertDatabaseHas('messages', ['id' => $inboundMessage->id, 'sender_type' => 'contact']);

        // Agent replies to conversation
        $replyResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => '¡Hola Juan! Te asisto con gusto.',
            ]);

        $replyResponse->assertStatus(201);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'agent',
            'direction' => 'outbound',
            'content' => '¡Hola Juan! Te asisto con gusto.',
        ]);
    }

    public function test_conversation_assignment_lifecycle(): void
    {
        $channel = Channel::create([
            'name' => 'Webchat Test',
            'type' => ChannelType::WEBCHAT,
        ]);

        $contact = Contact::create([
            'display_name' => 'María López',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'started_at' => now(),
        ]);

        // Assign to adminUser
        $assignResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/assign", [
                'assigned_user_id' => $this->adminUser->id,
                'reason' => 'Escalado por solicitud del cliente',
            ]);

        $assignResponse->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'assigned_user_id' => $this->adminUser->id,
            'status' => 'assigned',
        ]);

        $this->assertDatabaseHas('conversation_assignments', [
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $this->adminUser->id,
            'assigned_by_user_id' => $this->adminUser->id,
            'reason' => 'Escalado por solicitud del cliente',
        ]);
    }

    public function test_flow_versioning_and_publishing_lifecycle(): void
    {
        // 1. Create Flow
        $createResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/flows', [
                'name' => 'Flujo de Bienvenida',
                'description' => 'Saluda al usuario y ofrece opciones',
            ]);

        $createResponse->assertStatus(201);
        $flowId = $createResponse->json('data.id');

        $flow = Flow::with('versions.nodes')->findOrFail($flowId);
        $this->assertEquals(FlowStatus::DRAFT, $flow->status);
        $this->assertCount(1, $flow->versions);

        $version1 = $flow->versions->first();
        $this->assertEquals(1, $version1->version_number);
        $this->assertEquals(FlowVersionStatus::DRAFT, $version1->status);

        // 2. Save graph with valid nodes and edges
        $saveGraphResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/v1/flows/{$flowId}/versions/{$version1->id}/graph", [
                'nodes' => [
                    [
                        'node_key' => 'start_1',
                        'type' => 'start',
                        'name' => 'Inicio',
                        'configuration' => [],
                    ],
                    [
                        'node_key' => 'msg_1',
                        'type' => 'message',
                        'name' => 'Mensaje Bienvenida',
                        'configuration' => ['text' => 'Hola'],
                    ],
                    [
                        'node_key' => 'end_1',
                        'type' => 'end',
                        'name' => 'Fin',
                        'configuration' => [],
                    ],
                ],
                'edges' => [
                    [
                        'source_node_key' => 'start_1',
                        'target_node_key' => 'msg_1',
                    ],
                    [
                        'source_node_key' => 'msg_1',
                        'target_node_key' => 'end_1',
                    ],
                ],
            ]);

        $saveGraphResponse->assertStatus(200);

        // 3. Publish Version 1
        $publishResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flowId}/versions/{$version1->id}/publish");

        $publishResponse->assertStatus(200);

        $flow->refresh();
        $version1->refresh();

        $this->assertEquals(FlowStatus::PUBLISHED, $flow->status);
        $this->assertEquals($version1->id, $flow->active_version_id);
        $this->assertEquals(FlowVersionStatus::PUBLISHED, $version1->status);

        // 4. Create Version 2 (draft for next iteration)
        $newVersionResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/flows/{$flowId}/versions", [
                'from_version_id' => $version1->id,
                'description' => 'Mejoras en el saludo',
            ]);

        $newVersionResponse->assertStatus(201)
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.status', 'draft');

        // Flow active_version_id is STILL version 1 while version 2 is in draft!
        $this->assertEquals($version1->id, $flow->fresh()->active_version_id);
    }
}
