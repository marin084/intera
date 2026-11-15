<?php

namespace App\Console\Commands;

use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Channel;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\WebchatChannelSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SetupE2ETestCommand extends Command
{
    protected $signature = 'test:setup-e2e';

    protected $description = 'Prepares database and users for automated Playwright E2E tests without exposing secrets';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

        // Supervisor user
        $supervisor = User::updateOrCreate(
            ['email' => 'supervisor@conexa.test'],
            [
                'name' => 'Supervisor E2E',
                'password' => Hash::make('TestSupervisorPass@2026'),
                'is_active' => true,
            ]
        );
        $supervisorRole = Role::where('name', 'supervisor')->first();
        if ($supervisorRole) {
            $supervisor->roles()->sync([$supervisorRole->id]);
        }

        // Agent user
        $agent = User::updateOrCreate(
            ['email' => 'agent@conexa.test'],
            [
                'name' => 'Agent E2E',
                'password' => Hash::make('TestAgentPass@2026'),
                'is_active' => true,
            ]
        );
        $agentRole = Role::where('name', 'agent')->first();
        if ($agentRole) {
            $agent->roles()->sync([$agentRole->id]);
        }

        // Agent B user
        $agentB = User::updateOrCreate(
            ['email' => 'agent_b@conexa.test'],
            [
                'name' => 'Agent B E2E',
                'password' => Hash::make('TestAgentPass@2026'),
                'is_active' => true,
            ]
        );
        if ($agentRole) {
            $agentB->roles()->sync([$agentRole->id]);
        }

        // Admin user
        $admin = User::updateOrCreate(
            ['email' => 'admin@conexa.test'],
            [
                'name' => 'Admin E2E',
                'password' => Hash::make('TestAdminPass@2026'),
                'is_active' => true,
            ]
        );
        $adminRole = Role::where('name', 'super_admin')->first();
        if ($adminRole) {
            $admin->roles()->sync([$adminRole->id]);
        }

        // Channel Settings & Channel
        $settings = WebchatChannelSettings::where('public_key', 'wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL')->first();
        if ($settings) {
            $channel = $settings->channel;
            $extraChannels = Channel::where('name', 'Webchat E2E')->where('id', '!=', $channel->id)->get();
            foreach ($extraChannels as $extra) {
                WebchatChannelSettings::where('channel_id', $extra->id)->delete();
                $extra->delete();
            }
            $channel->update(['name' => 'Webchat E2E', 'is_active' => true]);
            $settings->update([
                'allowed_origins' => [
                    'http://localhost:3000',
                    'http://127.0.0.1:3000',
                    'http://localhost:5173',
                    'http://localhost:3001',
                ],
                'display_name' => 'Soporte Conexa E2E',
                'accent_color' => '#2563eb',
                'launcher_position' => 'bottom-right',
            ]);
        } else {
            $channel = Channel::firstOrCreate(
                ['name' => 'Webchat E2E'],
                [
                    'type' => 'webchat',
                    'is_active' => true,
                    'configuration' => [
                        'welcome_message' => '¡Hola! ¿En qué podemos ayudarte?',
                        'theme_color' => '#2563eb',
                    ],
                ]
            );
            $channel->update(['is_active' => true]);

            $settings = WebchatChannelSettings::create([
                'channel_id' => $channel->id,
                'public_key' => 'wc_pub_4Ibz08ynJ1tsvts8u1EXg3DIuxtZHWbL',
                'allowed_origins' => [
                    'http://localhost:3000',
                    'http://127.0.0.1:3000',
                    'http://localhost:5173',
                    'http://localhost:3001',
                ],
                'display_name' => 'Soporte Conexa E2E',
                'accent_color' => '#2563eb',
                'launcher_position' => 'bottom-right',
            ]);
        }

        // Find or create Entry Flow with handoff and negative branch
        $flow = Flow::where('name', 'Webchat E2E Flow')->first();

        if (! $flow) {
            $flow = Flow::create([
                'name' => 'Webchat E2E Flow',
                'description' => 'Flujo para pruebas end-to-end con handoff humano y cierre condicional',
                'status' => FlowStatus::PUBLISHED,
                'channel_applicability' => ['webchat'],
                'created_by' => $admin->id,
            ]);
        } else {
            $flow->update(['status' => FlowStatus::PUBLISHED]);
        }

        $version = $flow->activeVersion;
        if (! $version) {
            $version = FlowVersion::create([
                'flow_id' => $flow->id,
                'version_number' => 1,
                'status' => FlowVersionStatus::PUBLISHED,
                'is_published' => true,
                'published_at' => now(),
                'created_by' => $admin->id,
            ]);
            $flow->update(['active_version_id' => $version->id]);
        } else {
            $version->update([
                'status' => FlowVersionStatus::PUBLISHED,
                'is_published' => true,
                'published_at' => now(),
            ]);
        }

        // Always recreate nodes and edges deterministically
        FlowEdge::where('flow_version_id', $version->id)->delete();
        FlowNode::where('flow_version_id', $version->id)->delete();

        // Nodes
        $start = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'name' => 'Inicio',
            'type' => 'start',
            'configuration' => [],
        ]);

        $msgWelcome = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_welcome',
            'name' => 'Bienvenida',
            'type' => 'message',
            'configuration' => ['text' => '¡Hola! Bienvenido al asistente virtual de Conexa.'],
        ]);

        $condHasName = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'cond_has_name',
            'name' => 'Verificar Nombre Conocido',
            'type' => 'condition',
            'configuration' => [
                'variable' => 'visitor_name',
                'operator' => 'exists',
            ],
        ]);

        $msgWelcomeBack = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_welcome_back',
            'name' => 'Bienvenida de Retorno',
            'type' => 'message',
            'configuration' => ['text' => '¡Hola de nuevo, {{visitor_name}}! Qué gusto verte otra vez.'],
        ]);

        $qName = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_name',
            'name' => 'Preguntar Nombre',
            'type' => 'question',
            'configuration' => [
                'text' => '¿Cuál es tu nombre?',
                'variable' => 'visitor_name',
                'input_type' => 'text',
                'contact_field' => 'display_name',
            ],
        ]);

        $msgGreeting = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_greeting',
            'name' => 'Saludo',
            'type' => 'message',
            'configuration' => ['text' => 'Mucho gusto, {{visitor_name}}.'],
        ]);

        $qAgent = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_agent',
            'name' => 'Preguntar Agente',
            'type' => 'question',
            'configuration' => [
                'text' => '¿Deseas hablar con un agente humano?',
                'variable' => 'want_agent',
                'input_type' => 'choice',
                'choices' => ['Sí quiero agente', 'No gracias'],
            ],
        ]);

        $condAgent = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'cond_agent',
            'name' => 'Condición Agente',
            'type' => 'condition',
            'configuration' => [
                'variable' => 'want_agent',
                'operator' => 'equals',
                'value' => 'Sí quiero agente',
            ],
        ]);

        $msgTransfer = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_transfer',
            'name' => 'Aviso Transferencia',
            'type' => 'message',
            'configuration' => ['text' => 'Perfecto, te transferimos de inmediato.'],
        ]);

        $transfer = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'transfer_1',
            'name' => 'Transferir a Agente',
            'type' => 'transfer_to_agent',
            'configuration' => ['message' => 'Un momento por favor, buscando agente disponible...'],
        ]);

        $msgNoAgent = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_no_agent',
            'name' => 'Despedida Sin Agente',
            'type' => 'message',
            'configuration' => ['text' => 'Entendido. Si necesitas algo más, aquí estaremos.'],
        ]);

        $end = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'name' => 'Fin',
            'type' => 'end',
            'configuration' => ['message' => 'Conversación finalizada. ¡Hasta pronto!'],
        ]);

        // Edges
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'start_1', 'target_node_key' => 'msg_welcome']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_welcome', 'target_node_key' => 'cond_has_name']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_has_name', 'target_node_key' => 'msg_welcome_back', 'condition_handle' => 'true']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_welcome_back', 'target_node_key' => 'q_agent']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_has_name', 'target_node_key' => 'q_name', 'condition_handle' => 'false']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'q_name', 'target_node_key' => 'msg_greeting']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_greeting', 'target_node_key' => 'q_agent']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'q_agent', 'target_node_key' => 'cond_agent']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_agent', 'target_node_key' => 'msg_transfer', 'condition_handle' => 'true']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_transfer', 'target_node_key' => 'transfer_1']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'cond_agent', 'target_node_key' => 'msg_no_agent', 'condition_handle' => 'false']);
        FlowEdge::create(['flow_version_id' => $version->id, 'source_node_key' => 'msg_no_agent', 'target_node_key' => 'end_1']);

        $channel->update(['entry_flow_id' => $flow->id]);

        // Clean prior test data safely
        DB::statement('TRUNCATE TABLE messages, flow_execution_steps, flow_executions, conversation_participants, conversations, contacts, webchat_sessions CASCADE');
        Cache::flush();

        $this->info('E2E environment ready.');

        return self::SUCCESS;
    }
}
