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
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\Runtime\Channels\Adapters\WhatsAppChannelAdapter;
use App\Services\Runtime\Channels\Adapters\WebchatChannelAdapter;
use App\Services\Runtime\FlowRuntimeEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImageNodeExecutionTest extends TestCase
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

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_image@conexa.test'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        $this->admin->roles()->attach(Role::where('name', 'super_admin')->first());

        $this->flow = Flow::create([
            'name' => 'Flujo con Nodos Multimedia',
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
        $file = UploadedFile::fake()->create('infografia.png', 50, 'image/png');

        $response = $this->postJson("/api/v1/flows/{$this->flow->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_attachment_upload_stores_image_and_returns_metadata(): void
    {
        Sanctum::actingAs($this->admin);

        $file = UploadedFile::fake()->create('infografia.png', 50, 'image/png');

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
        $this->assertEquals('infografia.png', $data['file_name']);
        $this->assertEquals('image/png', $data['mime_type']);
        $this->assertGreaterThan(0, $data['size_bytes']);
    }

    public function test_attachment_upload_rejects_unauthorized_or_dangerous_files(): void
    {
        Sanctum::actingAs($this->admin);

        $dangerousFile = UploadedFile::fake()->create('script.php', 100, 'application/x-httpd-php');

        $response = $this->postJson("/api/v1/flows/{$this->flow->id}/attachments", [
            'file' => $dangerousFile,
        ]);

        $response->assertStatus(422);
    }

    public function test_attachment_upload_rejects_file_exceeding_5mb(): void
    {
        Sanctum::actingAs($this->admin);

        // 6 MB image
        $hugeFile = UploadedFile::fake()->create('grande.png', 6 * 1024, 'image/png');

        $response = $this->postJson("/api/v1/flows/{$this->flow->id}/attachments", [
            'file' => $hugeFile,
        ]);

        $response->assertStatus(422);
    }

    public function test_image_node_execution_emits_message_with_image_type_and_attachment_payload(): void
    {
        Event::fake([MessageCreated::class]);

        $channel = Channel::create([
            'name' => 'Webchat Soporte',
            'type' => ChannelType::WEBCHAT,
            'is_active' => true,
        ]);

        $contact = Contact::create([
            'channel_id' => $channel->id,
            'external_id' => 'user_123',
            'display_name' => 'María Pérez',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
        ]);

        // Construct graph: START -> IMAGE -> END
        $startNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
            'position_x' => 100,
            'position_y' => 100,
        ]);

        $imageNode = FlowNode::create([
            'flow_version_id' => $this->version->id,
            'node_key' => 'node_image_1',
            'type' => FlowNodeType::IMAGE,
            'name' => 'Enviar Infografía',
            'configuration' => [
                'url' => 'https://storage.conexa.test/flows/comprobante.png',
                'caption' => 'Hola {{nombre}}, aquí tienes las instrucciones de pago.',
                'file_name' => 'comprobante.png',
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
            'target_node_key' => 'node_image_1',
        ]);

        FlowEdge::create([
            'flow_version_id' => $this->version->id,
            'source_node_key' => 'node_image_1',
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

        // Find emitted message from image node
        $imageMessage = Message::where('conversation_id', $conversation->id)
            ->where('flow_node_id', $imageNode->id)
            ->first();

        $this->assertNotNull($imageMessage);
        $this->assertEquals(MessageType::IMAGE, $imageMessage->message_type);
        $this->assertEquals(MessageDirection::OUTBOUND, $imageMessage->direction);
        $this->assertEquals(MessageSenderType::BOT, $imageMessage->sender_type);
        $this->assertEquals('Hola María Pérez, aquí tienes las instrucciones de pago.', $imageMessage->content);

        // Verify payload.attachment
        $this->assertIsArray($imageMessage->payload);
        $this->assertArrayHasKey('attachment', $imageMessage->payload);
        $attachment = $imageMessage->payload['attachment'];
        $this->assertEquals('https://storage.conexa.test/flows/comprobante.png', $attachment['url']);
        $this->assertEquals('comprobante.png', $attachment['file_name']);
        $this->assertEquals('image/png', $attachment['mime_type']);
        $this->assertEquals('Hola María Pérez, aquí tienes las instrucciones de pago.', $attachment['caption']);

        // Verify MessageCreated broadcast
        Event::assertDispatched(MessageCreated::class, function ($event) use ($imageMessage) {
            return $event->message->id === $imageMessage->id;
        });

        // Verify channel adapters format
        $webchatAdapter = new WebchatChannelAdapter();
        $webchatOutbound = $webchatAdapter->formatOutbound($imageMessage);
        $this->assertEquals('image', $webchatOutbound['message_type']);
        $this->assertEquals('https://storage.conexa.test/flows/comprobante.png', $webchatOutbound['payload']['attachment']['url']);

        $whatsAppAdapter = new WhatsAppChannelAdapter();
        $whatsAppOutbound = $whatsAppAdapter->formatOutbound($imageMessage);
        $this->assertEquals('image', $whatsAppOutbound['type']);
        $this->assertEquals('https://storage.conexa.test/flows/comprobante.png', $whatsAppOutbound['image']['link']);
        $this->assertEquals('Hola María Pérez, aquí tienes las instrucciones de pago.', $whatsAppOutbound['image']['caption']);
    }
}
