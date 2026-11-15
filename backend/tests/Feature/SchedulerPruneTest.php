<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\WebchatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulerPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_deletes_expired_sessions_and_preserves_active(): void
    {
        $channel = Channel::create([
            'name' => 'Webchat Test Channel',
            'type' => 'webchat',
            'is_active' => true,
            'settings' => [
                'allowed_origins' => ['http://localhost:3000'],
            ],
        ]);

        $contact = Contact::create([
            'channel_id' => $channel->id,
            'identifier' => 'vis_prune_test',
            'name' => 'Prune Test Visitor',
        ]);

        // 1. Create expired session (expired 2 days ago)
        $expiredSession = WebchatSession::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'token_hash' => hash('sha256', Str::random(40)),
            'visitor_id' => 'vis_expired_123',
            'origin' => 'http://localhost:3000',
            'last_seen_at' => now()->subDays(2),
            'expires_at' => now()->subDays(2),
        ]);

        // 2. Create active session (expires in 10 days)
        $activeSession = WebchatSession::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'token_hash' => hash('sha256', Str::random(40)),
            'visitor_id' => 'vis_active_456',
            'origin' => 'http://localhost:3000',
            'last_seen_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        // Run prune command
        $this->artisan('webchat:sessions:prune')
            ->expectsOutputToContain('Se eliminaron 1 sesiones de webchat expiradas.')
            ->assertExitCode(0);

        // Verify expired is gone and active remains
        $this->assertDatabaseMissing('webchat_sessions', ['id' => $expiredSession->id]);
        $this->assertDatabaseHas('webchat_sessions', ['id' => $activeSession->id]);
    }
}
