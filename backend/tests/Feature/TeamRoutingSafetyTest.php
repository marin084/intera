<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Routing\ConversationAssignmentService;
use App\Services\Routing\ConversationQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeamRoutingSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_capacity_is_rechecked_when_two_assignments_compete_for_the_last_slot(): void
    {
        $role = $this->agentRole(1);
        $channel = $this->channel();
        $contact = $this->contact();
        $team = $this->team('Equipo Webchat');
        $agent = User::create([
            'name' => 'Agente',
            'email' => 'agent-routing@example.test',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);
        $agent->roles()->attach($role);
        $agent->channels()->attach($channel);
        $agent->teams()->attach($team);

        $older = $this->waitingConversation($channel, $contact, $team);
        $newer = $this->waitingConversation($channel, $contact, $team);
        $service = app(ConversationAssignmentService::class);

        // Both attempts may have selected the agent from the same stale candidate snapshot.
        $first = $service->assignToAgent($older, $agent, 'FIFO test');
        $second = $service->assignToAgent($newer, $agent, 'FIFO test');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, Conversation::query()->where('assigned_user_id', $agent->id)->where('status', ConversationStatus::ASSIGNED)->count());
        $this->assertSame(ConversationStatus::WAITING_AGENT, $newer->fresh()->status);
    }

    public function test_assignment_rechecks_channel_access_after_candidate_selection(): void
    {
        $role = $this->agentRole(3);
        $channel = $this->channel();
        $contact = $this->contact();
        $team = $this->team('Equipo Messenger');
        $agent = User::create([
            'name' => 'Agente',
            'email' => 'agent-stale@example.test',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);
        $agent->roles()->attach($role);
        $agent->channels()->attach($channel);
        $agent->teams()->attach($team);
        $agent->load(['channels', 'teams']);
        $conversation = $this->waitingConversation($channel, $contact, $team);

        // Revoke access after the in-memory candidate was selected.
        $agent->channels()->detach($channel);

        $this->assertNull(app(ConversationAssignmentService::class)->assignToAgent($conversation, $agent));
        $this->assertNull($conversation->fresh()->assigned_user_id);
        $this->assertSame(ConversationStatus::WAITING_AGENT, $conversation->fresh()->status);
    }

    public function test_flow_handoff_can_route_into_an_explicit_team_queue(): void
    {
        $channel = $this->channel();
        $contact = $this->contact();
        $team = $this->team('Equipo de Donaciones');
        $conversation = $this->waitingConversation($channel, $contact, $this->team('Equipo General Test'));

        $routed = app(ConversationQueueService::class)->enter($conversation, $team->id);

        $this->assertSame($team->id, $routed?->team_id);
        $this->assertDatabaseHas('conversation_queue_entries', [
            'conversation_id' => $conversation->id,
            'team_id' => $team->id,
            'exit_reason' => null,
        ]);
    }

    private function channel(): Channel
    {
        return Channel::create([
            'name' => 'Canal Test',
            'type' => ChannelType::WEBCHAT,
            'status' => 'active',
            'configuration' => [],
        ]);
    }

    private function contact(): Contact
    {
        return Contact::create([
            'display_name' => 'Contacto Test',
            'external_identifier' => 'routing-'.uniqid(),
        ]);
    }

    private function team(string $name): Team
    {
        return Team::create([
            'name' => $name,
            'description' => null,
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    private function agentRole(int $capacity): Role
    {
        return Role::create([
            'name' => 'agent',
            'display_name' => 'Agente',
            'description' => 'Rol de pruebas',
            'max_concurrent_conversations' => $capacity,
        ]);
    }

    private function waitingConversation(Channel $channel, Contact $contact, Team $team): Conversation
    {
        return Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'team_id' => $team->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
            'queued_at' => now(),
        ]);
    }
}
