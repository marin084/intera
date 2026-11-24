<?php

namespace App\Console\Commands;

use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\WebchatChannelSettings;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a deterministic, disposable two-tenant dataset for Playwright.
 *
 * This command is intentionally separate from test:setup-e2e because the
 * latter is a legacy single-workspace fixture used by existing tests.
 */
class SetupMultitenancyE2ECommand extends Command
{
    protected $signature = 'test:setup-multitenancy-e2e
        ';

    protected $description = 'Creates the disposable two-workspace fixture used by multitenancy E2E tests';

    private const WORKSPACE_A_ID = '11111111-1111-4111-8111-111111111111';
    private const WORKSPACE_B_ID = '22222222-2222-4222-8222-222222222222';
    private const CHANNEL_A_KEY = 'wc_pub_e2e_workspace_a_20261120';
    private const CHANNEL_B_KEY = 'wc_pub_e2e_workspace_b_20261120';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->components->error('Fixture bloqueado en producción. Usa APP_ENV=testing o local en un entorno desechable.');

            return self::FAILURE;
        }

        $this->call('db:seed', [
            '--class' => RolePermissionSeeder::class,
            '--force' => true,
        ]);

        $fixture = DB::transaction(function (): array {
            $roles = Role::query()->whereIn('name', ['super_admin', 'admin', 'agent'])->get()->keyBy('name');

            $platform = $this->upsertUser(
                email: 'e2e.platform@intera.test',
                name: 'E2E Platform Admin',
                password: $this->fixturePassword('E2E_PLATFORM_PASSWORD'),
                isPlatformAdmin: true,
            );
            $adminA = $this->upsertUser(
                email: 'e2e.admin.a@intera.test',
                name: 'E2E Admin Workspace A',
                password: $this->fixturePassword('E2E_ADMIN_A_PASSWORD'),
            );
            $adminB = $this->upsertUser(
                email: 'e2e.admin.b@intera.test',
                name: 'E2E Admin Workspace B',
                password: $this->fixturePassword('E2E_ADMIN_B_PASSWORD'),
            );
            $sharedAgent = $this->upsertUser(
                email: 'e2e.agent.shared@intera.test',
                name: 'E2E Shared Agent',
                password: $this->fixturePassword('E2E_SHARED_AGENT_PASSWORD'),
            );

            $platform->roles()->sync([$roles['super_admin']->id]);
            $adminA->roles()->sync([$roles['admin']->id]);
            $adminB->roles()->sync([$roles['admin']->id]);
            $sharedAgent->roles()->sync([$roles['agent']->id]);

            // The fixed IDs make cross-tenant assertions possible without
            // leaking fixture state through a separate file or API endpoint.
            // Remove only records belonging to the disposable fixture so the
            // command can be rerun between tests without touching real data.
            $this->purgeFixtureWorkspaces([self::WORKSPACE_A_ID, self::WORKSPACE_B_ID]);

            $workspaceA = $this->upsertWorkspace(self::WORKSPACE_A_ID, 'E2E Workspace A', 'e2e-workspace-a', $platform->id);
            $workspaceB = $this->upsertWorkspace(self::WORKSPACE_B_ID, 'E2E Workspace B', 'e2e-workspace-b', $platform->id);

            $this->attachMembership($workspaceA, $platform, $roles['admin']->id);
            $this->attachMembership($workspaceB, $platform, $roles['admin']->id);
            $this->attachMembership($workspaceA, $adminA, $roles['admin']->id);
            $this->attachMembership($workspaceB, $adminB, $roles['admin']->id);
            $this->attachMembership($workspaceA, $sharedAgent, $roles['agent']->id);
            $this->attachMembership($workspaceB, $sharedAgent, $roles['agent']->id);

            $platform->forceFill(['active_workspace_id' => $workspaceA->id])->saveQuietly();
            $adminA->forceFill(['active_workspace_id' => $workspaceA->id])->saveQuietly();
            $adminB->forceFill(['active_workspace_id' => $workspaceB->id])->saveQuietly();
            $sharedAgent->forceFill(['active_workspace_id' => $workspaceA->id])->saveQuietly();

            $tenantA = $this->createTenantData($workspaceA, $adminA, self::CHANNEL_A_KEY, 'A');
            $tenantB = $this->createTenantData($workspaceB, $adminB, self::CHANNEL_B_KEY, 'B');

            foreach ([
                [$platform, $tenantA['channel']],
                [$platform, $tenantB['channel']],
                [$adminA, $tenantA['channel']],
                [$adminB, $tenantB['channel']],
            ] as [$user, $channel]) {
                DB::table('channel_user')->insertOrIgnore([
                    'user_id' => $user->id,
                    'channel_id' => $channel->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ([$tenantA, $tenantB] as $tenant) {
                foreach ([$platform, $sharedAgent] as $user) {
                    DB::table('team_user')->insertOrIgnore([
                        'team_id' => $tenant['team']->id,
                        'user_id' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->seedInboxConversation($tenantA, 'E2E Contact A');
            $this->seedInboxConversation($tenantB, 'E2E Contact B');

            return [
                'workspace_a' => $workspaceA->id,
                'workspace_b' => $workspaceB->id,
                'channel_a' => self::CHANNEL_A_KEY,
                'channel_b' => self::CHANNEL_B_KEY,
            ];
        });

        $this->components->info('Fixture multitenancy E2E listo.');
        $this->line('workspace_a='.$fixture['workspace_a']);
        $this->line('workspace_b='.$fixture['workspace_b']);
        $this->line('channel_a='.$fixture['channel_a']);
        $this->line('channel_b='.$fixture['channel_b']);

        return self::SUCCESS;
    }

    private function upsertUser(string $email, string $name, string $password, bool $isPlatformAdmin = false): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
                'is_platform_admin' => $isPlatformAdmin,
                'active_workspace_id' => null,
            ]
        );
    }

    private function fixturePassword(string $environmentKey): string
    {
        $password = (string) env($environmentKey, '');

        if (mb_strlen($password) < 12) {
            throw new \InvalidArgumentException("{$environmentKey} must contain at least 12 characters.");
        }

        return $password;
    }

    /**
     * Delete the fixture graph in foreign-key order. Workspace deletion does
     * not cascade every conversation-related table, so relying on a parent
     * delete would make the second E2E test run fail.
     *
     * @param  list<string>  $workspaceIds
     */
    private function purgeFixtureWorkspaces(array $workspaceIds): void
    {
        $channelIds = DB::table('channels')->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();
        $contactIds = DB::table('contacts')->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();
        $conversationIds = DB::table('conversations')->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();
        $flowIds = DB::table('flows')->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();
        $teamIds = DB::table('teams')->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();
        $tagIds = DB::table('tags')->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();
        $versionIds = $flowIds === []
            ? []
            : DB::table('flow_versions')->whereIn('flow_id', $flowIds)->pluck('id')->all();
        $executionIds = $conversationIds === []
            ? []
            : DB::table('flow_executions')->whereIn('conversation_id', $conversationIds)->pluck('id')->all();

        DB::table('users')->whereIn('active_workspace_id', $workspaceIds)->update(['active_workspace_id' => null]);

        if ($conversationIds !== []) {
            DB::table('conversation_read_states')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('conversation_tag')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('conversation_assignments')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('conversation_participants')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('conversation_queue_entries')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('flow_simulation_sessions')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('webchat_sessions')->whereIn('active_conversation_id', $conversationIds)->update(['active_conversation_id' => null]);
            DB::table('messages')->whereIn('conversation_id', $conversationIds)->delete();
        }

        if ($executionIds !== []) {
            DB::table('flow_execution_steps')->whereIn('flow_execution_id', $executionIds)->delete();
            DB::table('flow_simulation_sessions')->whereIn('flow_execution_id', $executionIds)->delete();
            DB::table('messages')->whereIn('flow_execution_id', $executionIds)->delete();
            DB::table('flow_executions')->whereIn('id', $executionIds)->delete();
        }

        if ($conversationIds !== []) {
            DB::table('conversations')->whereIn('id', $conversationIds)->delete();
        }

        if ($versionIds !== []) {
            DB::table('flow_publications')->whereIn('flow_version_id', $versionIds)->orWhereIn('previous_version_id', $versionIds)->delete();
            DB::table('flow_edges')->whereIn('flow_version_id', $versionIds)->delete();
            DB::table('flow_nodes')->whereIn('flow_version_id', $versionIds)->delete();
        }

        if ($flowIds !== []) {
            DB::table('channels')->whereIn('entry_flow_id', $flowIds)->update(['entry_flow_id' => null]);
            DB::table('flows')->whereIn('id', $flowIds)->update(['active_version_id' => null]);
            DB::table('saved_funnels')->whereIn('flow_id', $flowIds)->delete();
            DB::table('flow_publications')->whereIn('flow_id', $flowIds)->delete();
            DB::table('flow_simulation_sessions')->whereIn('flow_id', $flowIds)->delete();
            DB::table('flow_executions')->whereIn('flow_id', $flowIds)->delete();
            DB::table('flow_versions')->whereIn('flow_id', $flowIds)->delete();
            DB::table('flows')->whereIn('id', $flowIds)->delete();
        }

        if ($channelIds !== []) {
            DB::table('webchat_sessions')->whereIn('channel_id', $channelIds)->delete();
            DB::table('channel_user')->whereIn('channel_id', $channelIds)->delete();
            DB::table('team_channels')->whereIn('channel_id', $channelIds)->delete();
            DB::table('webchat_channel_settings')->whereIn('channel_id', $channelIds)->delete();
            DB::table('whatsapp_media_cache')->whereIn('channel_id', $channelIds)->delete();
            DB::table('channels')->whereIn('id', $channelIds)->delete();
        }

        if ($teamIds !== []) {
            DB::table('team_user')->whereIn('team_id', $teamIds)->delete();
            DB::table('teams')->whereIn('id', $teamIds)->delete();
        }

        if ($contactIds !== []) {
            DB::table('webchat_sessions')->whereIn('contact_id', $contactIds)->delete();
            DB::table('contacts')->whereIn('id', $contactIds)->delete();
        }

        if ($tagIds !== []) {
            DB::table('conversation_tag')->whereIn('tag_id', $tagIds)->delete();
            DB::table('tags')->whereIn('id', $tagIds)->delete();
        }

        DB::table('agent_presence_sessions')->whereIn('workspace_id', $workspaceIds)->delete();
        DB::table('audit_logs')->whereIn('workspace_id', $workspaceIds)->delete();
        DB::table('canned_responses')->whereIn('workspace_id', $workspaceIds)->delete();
        DB::table('saved_funnels')->whereIn('workspace_id', $workspaceIds)->delete();
        DB::table('workspace_user')->whereIn('workspace_id', $workspaceIds)->delete();
        DB::table('workspaces')->whereIn('id', $workspaceIds)->delete();
    }

    private function upsertWorkspace(string $id, string $name, string $slug, int $ownerId): Workspace
    {
        $workspace = Workspace::query()->firstOrNew(['id' => $id]);
        $workspace->forceFill([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'initial_owner_id' => $ownerId,
            'configuration' => ['e2e_fixture' => true],
        ])->save();

        return $workspace->refresh();
    }

    private function attachMembership(Workspace $workspace, User $user, int $roleId): void
    {
        WorkspaceUser::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['role_id' => $roleId, 'is_active' => true]
        );
    }

    /** @return array{workspace: Workspace, flow: Flow, channel: Channel, team: Team} */
    private function createTenantData(Workspace $workspace, User $creator, string $publicKey, string $suffix): array
    {
        $flow = Flow::query()->create([
            'workspace_id' => $workspace->id,
            'name' => "E2E Flow {$suffix}",
            'description' => "Flujo aislado del workspace {$suffix}.",
            'status' => FlowStatus::PUBLISHED,
            'channel_applicability' => ['webchat'],
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $version = FlowVersion::query()->create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'description' => 'Versión publicada para aislamiento E2E.',
            'published_at' => now(),
            'published_by' => $creator->id,
        ]);

        $start = FlowNode::query()->create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_e2e',
            'type' => 'start',
            'name' => 'Inicio E2E',
            'configuration' => [],
        ]);
        $message = FlowNode::query()->create([
            'flow_version_id' => $version->id,
            'node_key' => 'message_e2e',
            'type' => 'message',
            'name' => 'Mensaje E2E',
            'configuration' => ['text' => "Bienvenido al workspace {$suffix}."],
        ]);
        FlowEdge::query()->create([
            'flow_version_id' => $version->id,
            'source_node_key' => $start->node_key,
            'target_node_key' => $message->node_key,
        ]);

        $flow->forceFill(['active_version_id' => $version->id])->save();

        $channel = Channel::query()->create([
            'workspace_id' => $workspace->id,
            'name' => "E2E Webchat {$suffix}",
            'type' => 'webchat',
            'is_active' => true,
            'entry_flow_id' => $flow->id,
            'configuration' => [
                'welcome_message' => "Bienvenido al workspace {$suffix}.",
                'theme_color' => '#47786d',
            ],
        ]);

        WebchatChannelSettings::query()->create([
            'channel_id' => $channel->id,
            'public_key' => $publicKey,
            'allowed_origins' => [
                'http://localhost:3000',
                'http://127.0.0.1:3000',
                'http://localhost:5173',
                'http://127.0.0.1:5173',
            ],
            'display_name' => "E2E Webchat {$suffix}",
            'accent_color' => '#47786d',
            'launcher_position' => 'bottom-right',
            'launcher_style' => 'circle',
            'availability_threshold' => 3,
            'availability_high_message' => '{count} agentes disponibles',
            'availability_low_message' => 'Agentes disponibles',
            'availability_zero_message' => 'No hay agentes disponibles',
            'launcher_cta_label' => 'Chatear ahora',
            'launcher_description' => 'Estamos aquí para ayudarte.',
        ]);

        $team = Team::query()->create([
            'workspace_id' => $workspace->id,
            'name' => "E2E Team {$suffix}",
            'description' => "Cola aislada del workspace {$suffix}.",
            'is_default' => true,
            'is_active' => true,
        ]);
        $team->channels()->sync([$channel->id]);

        return compact('workspace', 'flow', 'channel', 'team');
    }

    /** @param array{workspace: Workspace, channel: Channel, team: Team} $tenant */
    private function seedInboxConversation(array $tenant, string $displayName): void
    {
        $contact = Contact::query()->create([
            'workspace_id' => $tenant['workspace']->id,
            'display_name' => $displayName,
            'external_identifier' => 'e2e_'.strtolower(str_replace(' ', '_', $displayName)),
        ]);

        $conversation = Conversation::query()->create([
            'workspace_id' => $tenant['workspace']->id,
            'channel_id' => $tenant['channel']->id,
            'contact_id' => $contact->id,
            'team_id' => $tenant['team']->id,
            'status' => 'waiting_agent',
            'queued_at' => now(),
            'started_at' => now(),
            'last_message_at' => now(),
            'bot_enabled' => false,
        ]);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'contact',
            'sender_id' => (string) $contact->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'visibility' => 'public',
            'status' => 'sent',
            'content' => "Mensaje de prueba del workspace {$tenant['workspace']->name}.",
            'sent_at' => now(),
        ]);
    }
}
