<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Routing\ConversationQueueService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultitenancyPhase3IsolationTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $agentRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->adminRole = Role::where('name', 'super_admin')->firstOrFail();
        $this->agentRole = Role::where('name', 'agent')->firstOrFail();
    }

    public function test_team_cannot_include_user_from_another_workspace(): void
    {
        [$adminA, $workspaceA] = $this->makeMember('admin-a@example.com', 'super_admin');
        [$userB] = $this->makeMember('agent-b@example.com', 'agent');

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/v1/teams', [
                'name' => 'Equipo A',
                'member_ids' => [$userB->id],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('member_ids');
        $this->assertDatabaseMissing('teams', [
            'workspace_id' => $workspaceA->id,
            'name' => 'Equipo A',
        ]);
    }

    public function test_team_cannot_include_channel_from_another_workspace(): void
    {
        [$adminA] = $this->makeMember('admin-a@example.com', 'super_admin');
        [, $workspaceB] = $this->makeMember('agent-b@example.com', 'agent');
        $channelB = Channel::create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Canal B',
            'type' => 'webchat',
        ]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/v1/teams', [
                'name' => 'Equipo A',
                'channel_ids' => [$channelB->id],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('channel_ids');
    }

    public function test_user_channel_access_rejects_channel_from_another_workspace(): void
    {
        [$adminA, $workspaceA] = $this->makeMember('admin-a@example.com', 'super_admin');
        [$agentA] = $this->makeMember('agent-a@example.com', 'agent', $workspaceA);
        [, $workspaceB] = $this->makeMember('agent-b@example.com', 'agent');
        $channelB = Channel::create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Canal B',
            'type' => 'webchat',
        ]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->putJson("/api/v1/users/{$agentA->id}/channels", [
                'channel_ids' => [$channelB->id],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'Uno o más canales no pertenecen al espacio de trabajo actual.');
    }

    public function test_channel_cannot_reference_flow_from_another_workspace(): void
    {
        [$adminA] = $this->makeMember('admin-a@example.com', 'super_admin');
        [, $workspaceB] = $this->makeMember('agent-b@example.com', 'agent');
        $flowB = Flow::create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Flujo B',
            'status' => 'draft',
            'channel_applicability' => ['webchat'],
        ]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/v1/channels', [
                'name' => 'Canal A',
                'type' => 'webchat',
                'entry_flow_id' => $flowB->id,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'El flujo seleccionado no pertenece al espacio de trabajo actual.');
    }

    public function test_user_from_another_workspace_cannot_be_edited_by_current_admin(): void
    {
        [$adminA] = $this->makeMember('admin-a@example.com', 'super_admin');
        [$userB] = $this->makeMember('agent-b@example.com', 'agent');

        $response = $this->actingAs($adminA, 'sanctum')
            ->putJson("/api/v1/users/{$userB->id}", ['name' => 'No autorizado']);

        $response->assertNotFound();
        $this->assertSame('agent-b@example.com', $userB->fresh()->email);
    }

    public function test_queue_rejects_conversation_and_team_from_different_workspaces(): void
    {
        [, $workspaceA] = $this->makeMember('admin-a@example.com', 'super_admin');
        [, $workspaceB] = $this->makeMember('agent-b@example.com', 'agent');

        $channelA = Channel::create([
            'workspace_id' => $workspaceA->id,
            'name' => 'Canal A',
            'type' => 'webchat',
        ]);
        $contactA = Contact::create([
            'workspace_id' => $workspaceA->id,
            'display_name' => 'Contacto A',
            'external_identifier' => 'contact-a',
        ]);
        $conversationA = Conversation::create([
            'workspace_id' => $workspaceA->id,
            'channel_id' => $channelA->id,
            'contact_id' => $contactA->id,
            'status' => 'bot',
        ]);
        $teamB = Team::create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Equipo B',
            'is_active' => true,
        ]);
        // Simulate legacy/corrupt data that the service must reject rather
        // than allowing it to influence SLA or routing metrics.
        $teamB->channels()->attach($channelA->id);

        $this->app->instance('current_workspace_id', $workspaceA->id);
        $this->expectException(ValidationException::class);

        app(ConversationQueueService::class)->enter($conversationA);
    }

    /** @return array{0: User, 1: Workspace} */
    private function makeMember(string $email, string $roleName, ?Workspace $workspace = null): array
    {
        $workspace ??= Workspace::create([
            'name' => 'Workspace '.str_replace('@example.com', '', $email),
            'slug' => str_replace(['@', '.'], '-', $email).'-'.uniqid(),
        ]);
        $user = User::create([
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'password' => Hash::make('SecurePassword2026!'),
            'is_active' => true,
            'active_workspace_id' => $workspace->id,
        ]);
        $role = $roleName === 'super_admin' ? $this->adminRole : $this->agentRole;
        WorkspaceUser::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        return [$user->fresh(), $workspace];
    }
}
