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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeamAssignmentConcurrencyPostgresTest extends TestCase
{
    use DatabaseMigrations;

    public function test_simultaneous_assignments_cannot_exceed_the_last_agent_slot(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This concurrency check requires PostgreSQL row locks.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This concurrency check requires the PHP pcntl extension.');
        }

        $role = Role::create([
            'name' => 'agent',
            'display_name' => 'Agente',
            'description' => 'Rol de prueba de concurrencia',
            'max_concurrent_conversations' => 1,
        ]);
        $channel = Channel::create([
            'name' => 'Canal Concurrencia',
            'type' => ChannelType::WEBCHAT,
            'status' => 'active',
            'configuration' => [],
        ]);
        $contact = Contact::create([
            'display_name' => 'Contacto de prueba',
            'external_identifier' => 'concurrency-'.uniqid(),
        ]);
        $team = Team::create([
            'name' => 'Equipo Concurrencia',
            'description' => null,
            'is_default' => false,
            'is_active' => true,
        ]);
        $agent = User::create([
            'name' => 'Agente Concurrencia',
            'email' => 'agent-concurrency@example.test',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);
        $agent->roles()->attach($role);
        $agent->channels()->attach($channel);
        $agent->teams()->attach($team);

        $conversations = collect(range(1, 2))->map(fn () => Conversation::create([
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'team_id' => $team->id,
            'status' => ConversationStatus::WAITING_AGENT,
            'assigned_user_id' => null,
            'is_simulation' => false,
            'queued_at' => now(),
        ]));

        $testId = bin2hex(random_bytes(8));
        $gate = sys_get_temp_dir()."/intera-assignment-{$testId}.go";
        $ready = [
            sys_get_temp_dir()."/intera-assignment-{$testId}-1.ready",
            sys_get_temp_dir()."/intera-assignment-{$testId}-2.ready",
        ];
        $results = [
            sys_get_temp_dir()."/intera-assignment-{$testId}-1.result",
            sys_get_temp_dir()."/intera-assignment-{$testId}-2.result",
        ];
        $children = [];

        try {
            foreach ($conversations as $index => $conversation) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Could not fork a worker for the concurrency check.');
                }

                if ($pid === 0) {
                    DB::purge();
                    file_put_contents($ready[$index], 'ready');
                    $deadline = microtime(true) + 15;
                    while (! file_exists($gate) && microtime(true) < $deadline) {
                        usleep(10_000);
                    }

                    if (! file_exists($gate)) {
                        exit(2);
                    }

                    $workerConversation = Conversation::findOrFail($conversation->id);
                    $workerAgent = User::findOrFail($agent->id);
                    $assigned = app(ConversationAssignmentService::class)
                        ->assignToAgent($workerConversation, $workerAgent, 'Concurrent capacity test');
                    file_put_contents($results[$index], $assigned ? 'assigned' : 'rejected');
                    exit(0);
                }

                $children[] = $pid;
            }

            $deadline = microtime(true) + 15;
            while ((! file_exists($ready[0]) || ! file_exists($ready[1])) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($ready[0], 'First worker did not reach the start barrier.');
            $this->assertFileExists($ready[1], 'Second worker did not reach the start barrier.');
            touch($gate);

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Concurrency worker did not finish successfully.');
            }

            $outcomes = array_map(fn (string $path) => file_get_contents($path), $results);
            sort($outcomes);
            $this->assertSame(['assigned', 'rejected'], $outcomes);
            $this->assertSame(1, Conversation::query()
                ->where('assigned_user_id', $agent->id)
                ->where('status', ConversationStatus::ASSIGNED)
                ->count());
            $this->assertSame(1, Conversation::query()
                ->where('status', ConversationStatus::WAITING_AGENT)
                ->whereNull('assigned_user_id')
                ->count());
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            foreach ([$gate, ...$ready, ...$results] as $path) {
                @unlink($path);
            }
        }
    }
}
