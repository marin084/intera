<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class WorkspaceRealtimeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->adminRole = Role::where('name', 'admin')->firstOrFail();
    }

    public function test_authenticated_broadcast_channels_are_scoped_to_the_conversation_workspace(): void
    {
        $workspaceA = $this->createWorkspace('Workspace A');
        $workspaceB = $this->createWorkspace('Workspace B');
        $conversationA = $this->createConversation($workspaceA, 'a');
        $conversationB = $this->createConversation($workspaceB, 'b');

        $eventA = new MessageCreated(new Message([
            'conversation_id' => $conversationA->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'public',
            'content' => 'Mensaje A',
        ]));
        $eventB = new MessageCreated(new Message([
            'conversation_id' => $conversationB->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'public',
            'content' => 'Mensaje B',
        ]));

        $channelsA = collect($eventA->broadcastOn())->pluck('name')->all();
        $channelsB = collect($eventB->broadcastOn())->pluck('name')->all();

        $this->assertContains("private-workspace.{$workspaceA->id}.inbox", $channelsA);
        $this->assertContains("private-workspace.{$workspaceA->id}.conversation.{$conversationA->id}", $channelsA);
        $this->assertNotContains("private-workspace.{$workspaceB->id}.inbox", $channelsA);
        $this->assertContains("private-workspace.{$workspaceB->id}.inbox", $channelsB);
        $this->assertNotContains("private-workspace.{$workspaceA->id}.inbox", $channelsB);
    }

    public function test_inbox_channel_authorization_rejects_a_foreign_workspace(): void
    {
        $workspaceA = $this->createWorkspace('Workspace A');
        $workspaceB = $this->createWorkspace('Workspace B');
        $adminA = $this->createAdmin($workspaceA, 'admin-a@example.com');

        $authorizer = Broadcast::getChannels()->get('workspace.{workspaceId}.inbox');

        $this->assertIsCallable($authorizer);
        $this->assertFalse($authorizer($adminA, $workspaceB->id));
    }

    public function test_inbox_channel_authorization_accepts_the_active_workspace(): void
    {
        $workspace = $this->createWorkspace('Workspace A');
        $admin = $this->createAdmin($workspace, 'admin-a@example.com');

        $authorizer = Broadcast::getChannels()->get('workspace.{workspaceId}.inbox');

        $this->assertIsCallable($authorizer);
        $this->assertTrue($authorizer($admin, $workspace->id));
    }

    private function createWorkspace(string $name): Workspace
    {
        return Workspace::create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
        ]);
    }

    private function createAdmin(Workspace $workspace, string $email): User
    {
        $user = User::create([
            'name' => 'Workspace Admin',
            'email' => $email,
            'password' => Hash::make('SecurePassword2026!'),
            'is_active' => true,
            'active_workspace_id' => $workspace->id,
        ]);
        WorkspaceUser::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role_id' => $this->adminRole->id,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    private function createConversation(Workspace $workspace, string $suffix): Conversation
    {
        $channel = Channel::create([
            'workspace_id' => $workspace->id,
            'name' => "Channel {$suffix}",
            'type' => ChannelType::WEBCHAT,
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'display_name' => "Contact {$suffix}",
            'external_identifier' => "contact-{$suffix}",
        ]);

        return Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::BOT,
        ]);
    }
}
