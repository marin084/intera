<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Events\ConversationUpdated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowExecution;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationContactDetailTest extends TestCase
{
    use RefreshDatabase;

    protected User $agent;

    protected Channel $channel;

    protected Contact $contact;

    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $agentRole = Role::where('name', 'agent')->first();
        $this->agent = User::create([
            'name' => 'Agente Soporte',
            'email' => 'agente@conexa.ai',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $this->agent->roles()->attach($agentRole);

        $this->channel = Channel::create([
            'name' => 'WhatsApp Soporte',
            'type' => ChannelType::WHATSAPP,
            'status' => 'active',
            'configuration' => [
                'phone_number_id' => '987654321',
                'access_token' => 'test-token',
            ],
        ]);

        $this->contact = Contact::create([
            'display_name' => 'Carlos Mendoza',
            'external_identifier' => 'carlos-123',
            'phone' => '+5215551234567',
            'email' => 'carlos@example.com',
            'country' => 'MX',
            'metadata' => [
                'ciudad' => 'CDMX',
            ],
        ]);

        $this->conversation = Conversation::create([
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => ConversationStatus::ASSIGNED,
            'assigned_user_id' => $this->agent->id,
            'is_simulation' => false,
            'metadata' => [
                'tags' => ['Cliente Frecuente'],
            ],
        ]);
    }

    public function test_conversation_detail_serializes_contact_variables_tags_and_attachments(): void
    {
        Sanctum::actingAs($this->agent);

        // Create flow and flow execution with context variables
        $flow = Flow::create([
            'name' => 'Flujo de Ventas',
            'channel_id' => $this->channel->id,
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => 'published',
            'definition' => ['nodes' => [], 'edges' => []],
        ]);

        FlowExecution::create([
            'conversation_id' => $this->conversation->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'status' => FlowExecutionStatus::WAITING_AGENT,
            'context' => [
                '$user_name' => 'Carlos',
                '$user_pais' => 'México',
                'interes' => 'Plan Premium',
                '_internal_token' => 'secret_hide_me',
            ],
            'started_at' => now(),
        ]);

        // Create media messages
        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::CONTACT->value,
            'direction' => MessageDirection::INBOUND->value,
            'message_type' => 'image',
            'content' => 'https://storage.conexa.ai/receipt.jpg',
            'payload' => [
                'attachment' => [
                    'url' => 'https://storage.conexa.ai/receipt.jpg',
                    'file_name' => 'comprobante.jpg',
                    'file_size' => 150000,
                    'mime_type' => 'image/jpeg',
                ],
            ],
        ]);

        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT->value,
            'sender_id' => (string) $this->agent->id,
            'direction' => MessageDirection::OUTBOUND->value,
            'message_type' => 'file',
            'content' => 'Contrato adjunto',
            'payload' => [
                'attachment' => [
                    'url' => 'https://storage.conexa.ai/contrato.pdf',
                    'file_name' => 'contrato_2026.pdf',
                    'file_size' => 450000,
                    'mime_type' => 'application/pdf',
                ],
            ],
        ]);

        // Create internal note
        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => MessageSenderType::AGENT->value,
            'sender_id' => (string) $this->agent->id,
            'direction' => MessageDirection::OUTBOUND->value,
            'message_type' => 'text',
            'visibility' => 'internal',
            'content' => 'Cliente solicitó descuento especial del 10%',
        ]);

        $response = $this->getJson("/api/v1/conversations/{$this->conversation->id}");

        $response->assertStatus(200);

        // 1. Verify contact details
        $this->assertEquals('Carlos Mendoza', $response->json('data.contact.display_name'));
        $this->assertEquals('+5215551234567', $response->json('data.contact.phone'));
        $this->assertEquals('carlos@example.com', $response->json('data.contact.email'));

        // 2. Verify variables (clean without internal keys)
        $variables = $response->json('variables');
        $this->assertEquals('Carlos', $variables['user_name'] ?? null);
        $this->assertEquals('México', $variables['user_pais'] ?? null);
        $this->assertEquals('Plan Premium', $variables['interes'] ?? null);
        $this->assertEquals('CDMX', $variables['ciudad'] ?? null);
        $this->assertArrayNotHasKey('_internal_token', $variables);

        // 3. Verify tags
        $tags = $response->json('tags');
        $tagNames = is_array($tags[0] ?? null) ? array_column($tags, 'name') : $tags;
        $this->assertContains('Cliente Frecuente', $tagNames);

        // 4. Verify attachments
        $attachments = $response->json('attachments');
        $this->assertCount(2, $attachments);
        $fileNames = array_column($attachments, 'file_name');
        $this->assertContains('comprobante.jpg', $fileNames);
        $this->assertContains('contrato_2026.pdf', $fileNames);

        // 5. Verify internal notes
        $internalNotes = $response->json('internal_notes');
        $this->assertCount(1, $internalNotes);
        $this->assertEquals('Cliente solicitó descuento especial del 10%', $internalNotes[0]['content']);
    }

    public function test_update_contact_name_via_conversation_endpoint(): void
    {
        Sanctum::actingAs($this->agent);
        Event::fake([ConversationUpdated::class]);

        $response = $this->patchJson("/api/v1/conversations/{$this->conversation->id}/contact", [
            'name' => 'Carlos Mendoza VIP',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('contact.display_name', 'Carlos Mendoza VIP');

        $this->assertDatabaseHas('contacts', [
            'id' => $this->contact->id,
            'display_name' => 'Carlos Mendoza VIP',
        ]);

        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_update_contact_name_via_contacts_endpoint(): void
    {
        Sanctum::actingAs($this->agent);
        Event::fake([ConversationUpdated::class]);

        $response = $this->patchJson("/api/v1/contacts/{$this->contact->id}", [
            'name' => 'Carlos Alberto Mendoza',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('contact.display_name', 'Carlos Alberto Mendoza');

        $this->assertDatabaseHas('contacts', [
            'id' => $this->contact->id,
            'display_name' => 'Carlos Alberto Mendoza',
        ]);

        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_conversation_tags_addition_and_removal(): void
    {
        Sanctum::actingAs($this->agent);
        Event::fake([ConversationUpdated::class]);

        // Add VIP tag
        $addRes = $this->postJson("/api/v1/conversations/{$this->conversation->id}/tags", [
            'tag' => 'VIP',
        ]);

        $addRes->assertStatus(200)
            ->assertJsonFragment(['VIP']);

        $freshTags = $this->conversation->fresh()->tags->pluck('name')->all();
        $this->assertContains('VIP', $freshTags);

        // Add Urgente tag
        $this->postJson("/api/v1/conversations/{$this->conversation->id}/tags", [
            'tag' => 'Urgente',
        ])->assertStatus(200);

        $freshTags = $this->conversation->fresh()->tags->pluck('name')->all();
        $this->assertContains('Urgente', $freshTags);

        // Remove VIP tag
        $delRes = $this->deleteJson("/api/v1/conversations/{$this->conversation->id}/tags/VIP");
        $delRes->assertStatus(200);

        $freshTags = $this->conversation->fresh()->tags->pluck('name')->all();
        $this->assertNotContains('VIP', $freshTags);
        $this->assertContains('Urgente', $freshTags);

        Event::assertDispatched(ConversationUpdated::class);
    }
}
