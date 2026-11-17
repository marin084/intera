<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationQueueEntry;
use App\Models\Message;
use App\Models\Team;
use App\Services\Analytics\RealtimeAnalyticsService;
use App\Services\ConversationSlaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationSlaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_waiting_conversation_exposes_queue_deadline_and_overdue_state(): void
    {
        $now = Carbon::parse('2026-11-17 12:00:00');
        Carbon::setTestNow($now);
        [$conversation, $team] = $this->conversationWithTeam($now->copy()->subSeconds(90), 60, 120);
        ConversationQueueEntry::create([
            'conversation_id' => $conversation->id,
            'team_id' => $team->id,
            'entered_at' => $now->copy()->subSeconds(90),
        ]);

        app(ConversationSlaService::class)->decorateInbox(collect([$conversation]));

        $this->assertSame(90, $conversation->sla['queue_wait_seconds']);
        $this->assertTrue($conversation->sla['queue_wait_overdue']);
        $this->assertFalse($conversation->sla['first_response_overdue']);
        $this->assertSame($now->copy()->subSeconds(30)->timestamp, Carbon::parse($conversation->sla['queue_deadline_at'])->timestamp);
    }

    public function test_first_response_clock_ignores_internal_agent_notes(): void
    {
        $now = Carbon::parse('2026-11-17 12:00:00');
        Carbon::setTestNow($now);
        [$conversation, $team] = $this->conversationWithTeam($now->copy()->subSeconds(100), 120, 120);
        $conversation->update(['status' => ConversationStatus::ASSIGNED]);
        ConversationQueueEntry::create([
            'conversation_id' => $conversation->id,
            'team_id' => $team->id,
            'entered_at' => $now->copy()->subSeconds(100),
            'assigned_at' => $now->copy()->subSeconds(90),
            'assigned_user_id' => null,
            'ended_at' => $now->copy()->subSeconds(90),
            'exit_reason' => 'assigned',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'internal',
            'content' => 'Nota interna',
            'sent_at' => $now->copy()->subSeconds(70),
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'public',
            'content' => 'Respuesta visible',
            'sent_at' => $now->copy()->subSeconds(40),
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::TEXT,
            'content' => '¿Me confirma?',
            'sent_at' => $now->copy()->subSeconds(30),
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => MessageSenderType::AGENT,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::TEXT,
            'visibility' => 'external',
            'content' => 'Claro.',
            'sent_at' => $now->copy()->subSeconds(10),
        ]);

        app(ConversationSlaService::class)->decorateInbox(collect([$conversation]));

        $this->assertFalse($conversation->sla['first_response_pending']);
        $this->assertSame(60, $conversation->sla['first_response_seconds']);

        $metrics = app(RealtimeAnalyticsService::class)->getRealtimeMetrics($conversation->channel_id);
        $this->assertSame(60, $metrics['operating_times']['avg_first_response_seconds']);
        $this->assertSame(20, $metrics['operating_times']['avg_next_response_seconds']);
    }

    public function test_team_summary_counts_waiting_conversation_as_first_response_sla_breach(): void
    {
        $now = Carbon::parse('2026-11-17 12:00:00');
        Carbon::setTestNow($now);
        [$conversation, $team] = $this->conversationWithTeam($now->copy()->subSeconds(100), 60, 60);
        ConversationQueueEntry::create([
            'conversation_id' => $conversation->id,
            'team_id' => $team->id,
            'entered_at' => $now->copy()->subSeconds(100),
        ]);

        $metrics = app(RealtimeAnalyticsService::class)->getRealtimeMetrics($conversation->channel_id);
        $summary = collect($metrics['team_sla_summary'])->firstWhere('team_id', $team->id);

        $this->assertNotNull($summary);
        $this->assertSame(1, $summary['waiting_count']);
        $this->assertSame(1, $summary['queue_overdue_count']);
        $this->assertSame(1, $summary['first_response_overdue_count']);
        $this->assertSame(0, $summary['first_response_sla_met_percent']);
    }

    /** @return array{Conversation, Team} */
    private function conversationWithTeam(Carbon $queuedAt, int $queueTarget, int $firstTarget): array
    {
        $channel = Channel::create([
            'name' => 'Canal SLA',
            'type' => ChannelType::WEBCHAT,
            'status' => 'active',
            'configuration' => [],
        ]);
        $contact = Contact::create([
            'display_name' => 'Contacto SLA',
            'external_identifier' => 'sla-'.uniqid(),
        ]);
        $team = Team::create([
            'name' => 'Equipo SLA',
            'description' => null,
            'is_default' => false,
            'is_active' => true,
            'queue_wait_target_seconds' => $queueTarget,
            'first_response_target_seconds' => $firstTarget,
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'team_id' => $team->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'queued_at' => $queuedAt,
            'is_simulation' => false,
        ]);

        return [$conversation, $team];
    }
}
