<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\FlowExecutionStatus;
use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\User;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use App\Services\Runtime\Channels\Adapters\WebchatChannelAdapter;
use App\Services\Runtime\FlowRuntimeEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FileNodeExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Flow $flow;
    protected FlowVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');

        $this->admin = User::create([
            'name' => 'Admin File User',
            'email' => 'admin_file@conexa.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $this->admin->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->flow = Flow::create([
            'name' => 'Flujo con Archivo',
            'description' => 'Test de nodo file',
            'status' => FlowStatus::DRAFT,
            'channel_applicability' => ['all'],
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->version = FlowVersion::create([
            'flow_id' => $this->flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::DRAFT,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_attachment_upload_requires_authentication(): void
    {
        $file = UploadedFile::fake()->create('catalogo.pdf', 50, 'application/pdf');

        $response = $this->postJson("/api/v1/flows/{$this->flow->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_attachment_upload_stores_document_and_returns_metadata(): void
    {
        Sanctum::actingAs($this->admin);

        $file = UploadedFile::fake()->create('catalogo_precios.pdf', 500, 'application/pdf');

        $response = $this->postJson("/api/v1/flows/{$this->flow->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'url',
                'file_name',
                'mime_type',
                'size_bytes',
            ]);

        $data = $response->json();
        $this->assertNotEmpty($data['url']);
        $this->assertEquals('catalogo_precios.pdf', $data['file_name']);
        $this->assertEquals('application/pdf', $data['mime_type']);
        $this->assertGreaterThan(0, $data['size_bytes']);
    }

    public function test_attachment_upload_accepts_file_around_6mb_successfully(): void
    {
        Sanctum::actingAs($this->admin);

        // 6 MB document (e.g. decretos.pdf of 5.1-6 MB)
        $file = UploadedFile::fake()->create('decretos.pdf', 6 * 1024, 'application/pdf');

        $response = $this->postJson("/api/v1/flows/{$this->flow->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(201);
        $this->assertEquals('decretos.pdf', $response->json('file_name'));
        $this->assertGreaterThanOrEqual(6 * 1024 * 1024, $response->json('size_bytes'));
    }

    public function test_attachment_upload_rejects_unauthorized_or_dangerous_files(): void
    {
        Sanctum::actingAs($this->admin);

        $dangerousFile = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $response = $this->postJson("/api/v1/flows/{$this->flow->id}/attachments", [
            'file' => $dangerousFile,
        ]);

        $response->assertStatus(422);
    }

    public function test_attachment_upload_rejects_file_exceeding_10mb(): void
    {
        Sanctum::actingAs($this->admin);

        // 11 MB document
        $hugeFile = UploadedFile::fake()->create('archivo_pesado.pdf', 11 * 1024, 'application/pdf');

        $response = $this->postJson("/api/v1/flows/{$this->flow->id}/attachments", [
            'file' => $hugeFile,
        ]);

        $response->assertStatus(422);
    }

    public function test_file_node_execution_emits_message_with_file_type_and_attachment_payload(): void
    {
        Event::fake([MessageCreated::class]);

        $channel = Channel::create([
            'name' => 'Webchat Soporte',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
        ]);

        $contact = Contact::create([
            'channel_id' => $channel->id,
            'external_id' => 'user_456',
            'display_name' => 'Carlos López',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
        ]);

        // Construct graph: START -> FILE -> END
        $startNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
            'position_x' => 100,
            'position_y' => 100,
        ]);

        $fileNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'node_file_1',
            'type' => FlowNodeType::FILE,
            'name' => 'Enviar Catálogo',
            'configuration' => [
                'url' => 'https://storage.conexa.test/flows/catalogo_2026.pdf',
                'caption' => 'Hola {{nombre}}, aquí tienes el catálogo solicitado.',
                'file_name' => 'catalogo_2026.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1048576,
            ],
            'position_x' => 100,
            'position_y' => 200,
        ]);

        $endNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'node_end',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => [],
            'position_x' => 100,
            'position_y' => 300,
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->version->id,
            'source_node_key' => 'node_start',
            'target_node_key' => 'node_file_1',
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->version->id,
            'source_node_key' => 'node_file_1',
            'target_node_key' => 'node_end',
        ]);

        $this->version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $this->flow->update(['active_version_id' => $this->version->id]);

        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start(
            $conversation,
            $this->version
        );

        $this->assertEquals(FlowExecutionStatus::COMPLETED, $execution->status);

        // Find emitted message from file node
        $fileMessage = Message::where('conversation_id', $conversation->id)
            ->where('flow_node_id', $fileNode->id)
            ->first();

        $this->assertNotNull($fileMessage);
        $this->assertEquals(MessageType::FILE, $fileMessage->message_type);
        $this->assertEquals(MessageDirection::OUTBOUND, $fileMessage->direction);
        $this->assertEquals(MessageSenderType::BOT, $fileMessage->sender_type);
        $this->assertEquals('Hola Carlos López, aquí tienes el catálogo solicitado.', $fileMessage->content);

        // Verify payload.attachment
        $this->assertIsArray($fileMessage->payload);
        $this->assertArrayHasKey('attachment', $fileMessage->payload);
        $attachment = $fileMessage->payload['attachment'];
        $this->assertEquals('https://storage.conexa.test/flows/catalogo_2026.pdf', $attachment['url']);
        $this->assertEquals('catalogo_2026.pdf', $attachment['file_name']);
        $this->assertEquals('application/pdf', $attachment['mime_type']);
        $this->assertEquals(1048576, $attachment['size_bytes']);
        $this->assertEquals('Hola Carlos López, aquí tienes el catálogo solicitado.', $attachment['caption']);

        // Verify MessageCreated broadcast
        Event::assertDispatched(MessageCreated::class, function ($event) use ($fileMessage) {
            return $event->message->id === $fileMessage->id;
        });

        // Verify channel adapters format
        $webchatAdapter = new WebchatChannelAdapter();
        $webchatOutbound = $webchatAdapter->formatOutbound($fileMessage);
        $this->assertEquals('file', $webchatOutbound['message_type']);
        $this->assertEquals('https://storage.conexa.test/flows/catalogo_2026.pdf', $webchatOutbound['payload']['attachment']['url']);
        $this->assertEquals('catalogo_2026.pdf', $webchatOutbound['payload']['attachment']['file_name']);

        $whatsAppAdapter = new WhatsAppChannelAdapter();
        $whatsAppOutbound = $whatsAppAdapter->formatOutbound($fileMessage);
        $this->assertEquals('document', $whatsAppOutbound['type']);
        $this->assertEquals('https://storage.conexa.test/flows/catalogo_2026.pdf', $whatsAppOutbound['document']['link']);
        $this->assertEquals('catalogo_2026.pdf', $whatsAppOutbound['document']['filename']);
        $this->assertEquals('Hola Carlos López, aquí tienes el catálogo solicitado.', $whatsAppOutbound['document']['caption']);
    }

    public function test_file_node_content_defaults_to_file_name_when_caption_is_empty(): void
    {
        $channel = Channel::create([
            'name' => 'Webchat Soporte',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
        ]);

        $contact = Contact::create([
            'channel_id' => $channel->id,
            'external_id' => 'user_789',
            'display_name' => 'Ana Gómez',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
        ]);

        $startNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
            'position_x' => 100,
            'position_y' => 100,
        ]);

        $fileNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'node_file_plain',
            'type' => FlowNodeType::FILE,
            'name' => 'Enviar Documento',
            'configuration' => [
                'url' => 'https://storage.conexa.test/flows/guia.docx',
                'caption' => '',
                'file_name' => 'guia_usuario.docx',
            ],
            'position_x' => 100,
            'position_y' => 200,
        ]);

        $endNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'node_end',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => [],
            'position_x' => 100,
            'position_y' => 300,
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->version->id,
            'source_node_key' => 'node_start',
            'target_node_key' => 'node_file_plain',
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->version->id,
            'source_node_key' => 'node_file_plain',
            'target_node_key' => 'node_end',
        ]);

        $this->version->update(['status' => FlowVersionStatus::PUBLISHED]);
        $this->flow->update(['active_version_id' => $this->version->id]);

        $engine = app(FlowRuntimeEngine::class);
        $execution = $engine->start($conversation, $this->version);

        $this->assertEquals(FlowExecutionStatus::COMPLETED, $execution->status);

        $fileMessage = Message::where('conversation_id', $conversation->id)
            ->where('flow_node_id', $fileNode->id)
            ->first();

        $this->assertNotNull($fileMessage);
        $this->assertEquals('guia_usuario.docx', $fileMessage->content);
        $this->assertEquals('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $fileMessage->payload['attachment']['mime_type']);
    }
}
