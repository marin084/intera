<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Events\ConversationAssigned;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\Message;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationTagAndTransferTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agentA;

    protected User $agentB;

    protected User $inactiveAgent;

    protected Channel $channel;

    protected Contact $contact;

    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\TagSeeder::class);

        $adminRole = Role::where('name', 'super_admin')->first();
        $agentRole = Role::where('name', 'agent')->first();

        $this->admin = User::create([
            'name' => 'Admin Conexa',
            'email' => 'admin@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->admin->roles()->attach($adminRole);

        $this->agentA = User::create([
            'name' => 'Agente Ana',
            'email' => 'ana@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->agentA->roles()->attach($agentRole);

        $this->agentB = User::create([
            'name' => 'Agente Bernardo',
            'email' => 'bernardo@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->agentB->roles()->attach($agentRole);

        $this->inactiveAgent = User::create([
            'name' => 'Agente Inactivo',
            'email' => 'inactivo@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => false,
        ]);
        $this->inactiveAgent->roles()->attach($agentRole);

        $this->channel = Channel::create([
            'name' => 'WhatsApp Central',
            'type' => ChannelType::WHATSAPP,
            'status' => 'active',
            'configuration' => [
                'phone_number_id' => '123456789',
                'access_token' => 'meta-token',
            ],
        ]);

        $this->contact = Contact::create([
            'display_name' => 'Roberto Gómez',
            'phone' => '+5215559876543',
            'email' => 'roberto@example.com',
            'country' => 'MX',
        ]);

        $this->conversation = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'is_simulation' => false,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        ConversationAssignment::create([
            'conversation_id' => $this->conversation->id,
            'assigned_user_id' => $this->agentA->id,
            'assigned_by_user_id' => $this->admin->id,
            'assigned_at' => now(),
            'reason' => 'Asignación inicial',
        ]);
    }

    public function test_tags_crud_endpoints(): void
    {
        Sanctum::actingAs($this->admin);

        // 1. List default tags
        $listRes = $this->getJson('/api/v1/tags');
        $listRes->assertStatus(200);
        $tags = $listRes->json('data');
        $this->assertNotEmpty($tags);

        $tagNames = array_column($tags, 'name');
        $this->assertContains('Petición de Oración', $tagNames);
        $this->assertContains('Promesa de Ofrenda', $tagNames);
        $this->assertContains('Depósito por Validar', $tagNames);
        $this->assertContains('Consulta General', $tagNames);
        $this->assertContains('Urgente', $tagNames);

        // 2. Create new tag
        $createRes = $this->postJson('/api/v1/tags', [
            'name' => 'Nuevo Creyente',
            'color' => '#10B981',
        ]);
        $createRes->assertStatus(201)
            ->assertJsonPath('data.name', 'Nuevo Creyente')
            ->assertJsonPath('data.slug', 'nuevo-creyente')
            ->assertJsonPath('data.color', '#10B981');

        $createdId = $createRes->json('data.id');

        // Duplicate name rejected
        $this->postJson('/api/v1/tags', [
            'name' => 'Nuevo Creyente',
        ])->assertStatus(422);

        // 3. Update tag
        $updateRes = $this->putJson("/api/v1/tags/{$createdId}", [
            'name' => 'Nuevo Miembro',
            'color' => '#8B5CF6',
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Nuevo Miembro')
            ->assertJsonPath('data.slug', 'nuevo-miembro')
            ->assertJsonPath('data.color', '#8B5CF6');

        // 4. Delete tag
        $deleteRes = $this->deleteJson("/api/v1/tags/{$createdId}");
        $deleteRes->assertStatus(200);
        $this->assertDatabaseMissing('tags', ['id' => $createdId]);
    }

    public function test_conversation_tag_sync_and_filter(): void
    {
        Sanctum::actingAs($this->agentA);
        Event::fake([ConversationUpdated::class]);

        $tag1 = Tag::where('name', 'Petición de Oración')->first();
        $tag2 = Tag::where('name', 'Urgente')->first();

        // 1. Sync tags via tag_ids
        $syncRes = $this->postJson("/api/v1/conversations/{$this->conversation->id}/tags", [
            'tag_ids' => [$tag1->id, $tag2->id],
        ]);

        $syncRes->assertStatus(200);
        $this->assertCount(2, $this->conversation->fresh()->tags);
        Event::assertDispatched(ConversationUpdated::class);

        // 2. Toggle tag via single tag_id (removes tag2)
        $toggleRes = $this->postJson("/api/v1/conversations/{$this->conversation->id}/tags", [
            'tag_id' => $tag2->id,
        ]);
        $toggleRes->assertStatus(200);
        $this->assertCount(1, $this->conversation->fresh()->tags);
        $this->assertEquals($tag1->id, $this->conversation->fresh()->tags->first()->id);

        // 3. Remove remaining tag via DELETE endpoint
        $delRes = $this->deleteJson("/api/v1/conversations/{$this->conversation->id}/tags/{$tag1->id}");
        $delRes->assertStatus(200);
        $this->assertCount(0, $this->conversation->fresh()->tags);

        // 4. Test filtering inbox by tag_id
        $this->conversation->tags()->attach($tag1->id, ['assigned_by' => $this->agentA->id]);

        $otherConv = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentA->id,
            'is_simulation' => false,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        // Filter with tag1 should return only this->conversation
        $filterRes = $this->getJson("/api/v1/conversations?tag_id={$tag1->id}");
        $filterRes->assertStatus(200);
        $convs = $filterRes->json('data');
        $this->assertCount(1, $convs);
        $this->assertEquals($this->conversation->id, $convs[0]['id']);
        $this->assertNotEmpty($convs[0]['tags']);
    }

    public function test_conversation_transfer_between_agents_with_internal_note(): void
    {
        Sanctum::actingAs($this->agentA);
        Event::fake([ConversationAssigned::class, ConversationUpdated::class, MessageCreated::class]);

        // Reject inactive user
        $failRes = $this->postJson("/api/v1/conversations/{$this->conversation->id}/transfer", [
            'target_user_id' => $this->inactiveAgent->id,
            'note' => 'Transferencia inválida',
        ]);
        $failRes->assertStatus(422);

        // Valid transfer to Agent B with note
        $transferRes = $this->postJson("/api/v1/conversations/{$this->conversation->id}/transfer", [
            'target_user_id' => $this->agentB->id,
            'note' => 'Favor de atender seguimiento de ofrenda.',
        ]);

        $transferRes->assertStatus(200)
            ->assertJsonPath('data.assigned_user_id', $this->agentB->id)
            ->assertJsonPath('data.status', 'assigned');

        // Verify conversation updated
        $this->conversation->refresh();
        $this->assertEquals($this->agentB->id, $this->conversation->assigned_user_id);
        $this->assertEquals(ConversationStatus::ASSIGNED, $this->conversation->status);

        // Verify assignment records: previous closed, new active
        $this->assertDatabaseHas('conversation_assignments', [
            'conversation_id' => $this->conversation->id,
            'assigned_user_id' => $this->agentA->id,
        ]);
        $this->assertNotNull(
            ConversationAssignment::where('conversation_id', $this->conversation->id)
                ->where('assigned_user_id', $this->agentA->id)
                ->first()
                ->unassigned_at
        );

        $newAssignment = ConversationAssignment::where('conversation_id', $this->conversation->id)
            ->where('assigned_user_id', $this->agentB->id)
            ->first();
        $this->assertNotNull($newAssignment);
        $this->assertNull($newAssignment->unassigned_at);
        $this->assertEquals($this->agentA->id, $newAssignment->assigned_by_user_id);

        // Verify internal note message created and NOT public
        $internalMsg = Message::where('conversation_id', $this->conversation->id)
            ->where('visibility', 'internal')
            ->first();
        $this->assertNotNull($internalMsg);
        $this->assertEquals(MessageSenderType::AGENT->value, $internalMsg->sender_type->value ?? $internalMsg->sender_type);
        $this->assertEquals((string) $this->agentA->id, $internalMsg->sender_id);
        $this->assertStringContainsString("🔄 Transferido a {$this->agentB->name}: Favor de atender seguimiento de ofrenda.", $internalMsg->content);

        // Verify events dispatched
        Event::assertDispatched(ConversationAssigned::class);
        Event::assertDispatched(ConversationUpdated::class);
        Event::assertDispatched(MessageCreated::class);
    }

    public function test_users_and_sessions_analytics_topics_distribution(): void
    {
        Sanctum::actingAs($this->admin);

        $tag1 = Tag::where('name', 'Petición de Oración')->first();
        $tag2 = Tag::where('name', 'Promesa de Ofrenda')->first();

        // Conversation 1 tagged with tag1
        $this->conversation->tags()->sync([$tag1->id]);

        // Conversation 2 tagged with tag1 and tag2
        $conv2 = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agentB->id,
            'is_simulation' => false,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);
        $conv2->tags()->sync([$tag1->id, $tag2->id]);

        // Conversation 3 untagged
        Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::CLOSED,
            'assigned_user_id' => null,
            'is_simulation' => false,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/analytics/users-and-sessions?channel_id={$this->channel->id}");
        $res->assertStatus(200);

        $topics = $res->json('data.topics_distribution');
        $this->assertIsArray($topics);
        $this->assertNotEmpty($topics);

        $tag1Topic = collect($topics)->firstWhere('name', 'Petición de Oración');
        $this->assertNotNull($tag1Topic);
        $this->assertEquals(2, $tag1Topic['count']);

        $tag2Topic = collect($topics)->firstWhere('name', 'Promesa de Ofrenda');
        $this->assertNotNull($tag2Topic);
        $this->assertEquals(1, $tag2Topic['count']);

        $this->assertEquals(1, $res->json('data.untagged_count'));

        // Test filtering by tag_id in analytics
        $filteredAnalytics = $this->getJson("/api/v1/analytics/users-and-sessions?channel_id={$this->channel->id}&tag_id={$tag2->id}");
        $filteredAnalytics->assertStatus(200);
        $this->assertEquals(1, $filteredAnalytics->json('data.kpis.total_sessions'));
    }
}
