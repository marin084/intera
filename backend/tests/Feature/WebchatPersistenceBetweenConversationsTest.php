<?php

namespace Tests\Feature;

use App\Enums\FlowNodeType;
use App\Enums\FlowStatus;
use App\Enums\FlowVersionStatus;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Models\WebchatChannelSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebchatPersistenceBetweenConversationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returning_visitor_persists_name_and_branches_in_new_conversation(): void
    {
        // 1. Create Webchat Channel with Settings
        $channel = Channel::create([
            'name' => 'Webchat Test Channel',
            'type' => 'webchat',
            'is_active' => true,
        ]);

        $publicKey = 'wc_pub_'.Str::random(32);
        WebchatChannelSettings::create([
            'channel_id' => $channel->id,
            'public_key' => $publicKey,
            'allowed_origins' => ['http://localhost:3000'],
            'display_name' => 'Test Webchat',
            'accent_color' => '#2563EB',
            'launcher_position' => 'bottom-right',
        ]);

        // 2. Create Flow with Condition branching on visitor_name / nombre
        $flow = Flow::create([
            'name' => 'Test Flow',
            'status' => FlowStatus::PUBLISHED,
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
        ]);

        $flow->update(['active_version_id' => $version->id]);
        $channel->update(['entry_flow_id' => $flow->id]);

        // Nodes
        $startNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
            'name' => 'Inicio',
            'configuration' => [],
        ]);

        $conditionNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_cond',
            'type' => FlowNodeType::CONDITION,
            'name' => 'Condición',
            'configuration' => [
                'logic' => 'or',
                'rules' => [
                    ['variable' => 'visitor_name', 'operator' => 'not_empty'],
                    ['variable' => 'nombre', 'operator' => 'not_empty'],
                ],
            ],
        ]);

        $greetingNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_greeting',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Saludo Retorno',
            'configuration' => ['text' => '¡Hola de nuevo, {{visitor_name}}! Qué gusto verte otra vez.'],
        ]);

        $questionNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_ask_name',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Preguntar Nombre',
            'configuration' => ['text' => '¿Cual es tu nombre?', 'variable' => 'visitor_name', 'input_type' => 'text'],
        ]);

        $thanksNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_thanks',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Gracias',
            'configuration' => ['text' => 'Gracias {{visitor_name}}, anotado.'],
        ]);

        $agentQNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_agent_q',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Preguntar Agente',
            'configuration' => ['text' => '¿Quieres agente?', 'variable' => 'var_agente', 'input_type' => 'choice', 'choices' => ['Sí', 'No']],
        ]);

        $endNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'node_end',
            'type' => FlowNodeType::END,
            'name' => 'Fin',
            'configuration' => ['message' => 'Hasta luego.'],
        ]);

        // Edges
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_start',
            'target_node_key' => 'node_cond',
            'condition_handle' => 'output',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_cond',
            'target_node_key' => 'node_greeting',
            'condition_handle' => 'true',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_greeting',
            'target_node_key' => 'node_agent_q',
            'condition_handle' => 'output',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_cond',
            'target_node_key' => 'node_ask_name',
            'condition_handle' => 'false',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_ask_name',
            'target_node_key' => 'node_thanks',
            'condition_handle' => 'output',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_thanks',
            'target_node_key' => 'node_agent_q',
            'condition_handle' => 'output',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_agent_q',
            'target_node_key' => 'node_end',
            'condition_handle' => 'output',
        ]);

        // 3. Embed Handshake
        $embedRes = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [
            'origin' => 'http://localhost:3000',
        ])->assertStatus(200);

        $embedToken = $embedRes->json('data.embed_token');

        // 4. Create Session
        $sessionRes = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", [
            'embed_token' => $embedToken,
            'origin' => 'http://localhost:3000',
        ])->assertStatus(201);

        $visitorToken = $sessionRes->json('data.visitor_token');

        // 5. Start Conversation 1 -> Condition is false -> asks name
        $start1 = $this->withHeader('Authorization', "Bearer {$visitorToken}")
            ->postJson('/api/v1/public/webchat/conversations/start')
            ->assertStatus(201);

        $conv1Id = $start1->json('data.conversation.id');
        $msgs1 = $start1->json('data.messages');
        $this->assertSame('¿Cual es tu nombre?', end($msgs1)['content']);

        // 6. Visitor sends name "Luis"
        $this->withHeader('Authorization', "Bearer {$visitorToken}")
            ->postJson('/api/v1/public/webchat/messages', [
                'client_message_id' => (string) Str::uuid(),
                'content' => 'Luis',
            ])->assertStatus(201);

        // Verify contact metadata in database
        $this->assertDatabaseHas('contacts', [
            'display_name' => 'Luis',
        ]);

        // 7. Visitor responds to agent question "No", closing Conversation 1
        $this->withHeader('Authorization', "Bearer {$visitorToken}")
            ->postJson('/api/v1/public/webchat/messages', [
                'client_message_id' => (string) Str::uuid(),
                'content' => 'No',
            ])->assertStatus(201);

        $this->assertDatabaseHas('conversations', [
            'id' => $conv1Id,
            'status' => 'closed',
        ]);

        // 8. Start Conversation 2 with SAME visitor_token -> Reuses conversation, inserts divider, and greets returning visitor
        $start2 = $this->withHeader('Authorization', "Bearer {$visitorToken}")
            ->postJson('/api/v1/public/webchat/conversations/start')
            ->assertStatus(201);

        $conv2Id = $start2->json('data.conversation.id');
        $this->assertSame($conv1Id, $conv2Id);
        $this->assertEquals(1, Conversation::where('channel_id', $channel->id)->count());

        $msgs2 = $start2->json('data.messages');
        $contents = array_column($msgs2, 'content');
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv1Id,
            'sender_type' => 'system',
            'visibility' => 'internal',
            'content' => '── Sesión finalizada ──',
        ]);
        $this->assertNotContains('── Sesión finalizada ──', $contents, 'Internal session dividers must not be exposed to visitors.');
        $this->assertContains('¡Hola de nuevo, Luis! Qué gusto verte otra vez.', $contents);
        $this->assertContains('¿Cual es tu nombre?', $contents);
    }

    public function test_reopening_closed_webchat_conversation_starts_entry_flow_and_cleans_closing_owner(): void
    {
        $channel = Channel::create([
            'name' => 'Webchat Reopen Channel',
            'type' => 'webchat',
            'is_active' => true,
        ]);

        $publicKey = 'wc_pub_'.Str::random(32);
        WebchatChannelSettings::create([
            'channel_id' => $channel->id,
            'public_key' => $publicKey,
            'allowed_origins' => ['http://localhost:3000'],
            'display_name' => 'Webchat Reopen Test',
        ]);

        $flow = Flow::create([
            'name' => 'Reopen Flow',
            'status' => FlowStatus::PUBLISHED,
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
            'definition' => [],
        ]);

        $startNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'name' => 'Start',
            'node_key' => 'node_start',
            'type' => FlowNodeType::START,
        ]);

        $textNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'name' => 'Greeting',
            'node_key' => 'node_text',
            'type' => FlowNodeType::MESSAGE,
            'configuration' => [
                'text' => 'Bienvenido al nuevo ciclo.',
            ],
        ]);

        $askNode = FlowNode::create([
            'flow_version_id' => $version->id,
            'name' => 'Question',
            'node_key' => 'node_question',
            'type' => FlowNodeType::QUESTION,
            'configuration' => [
                'text' => '¿En qué te podemos apoyar hoy?',
                'variable' => 'user_query',
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_start',
            'target_node_key' => 'node_text',
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'node_text',
            'target_node_key' => 'node_question',
        ]);

        $flow->update(['active_version_id' => $version->id]);
        $channel->update(['entry_flow_id' => $flow->id]);

        // Embed Handshake & Session
        $embedRes = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [
            'origin' => 'http://localhost:3000',
        ])->assertStatus(200);

        $embedToken = $embedRes->json('data.embed_token');

        $sessionRes = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", [
            'embed_token' => $embedToken,
            'origin' => 'http://localhost:3000',
        ])->assertStatus(201);

        $visitorToken = $sessionRes->json('data.visitor_token');

        // Start initial conversation
        $start1 = $this->withHeader('Authorization', "Bearer {$visitorToken}")
            ->postJson('/api/v1/public/webchat/conversations/start')
            ->assertStatus(201);

        $convId = $start1->json('data.conversation.id');
        $conversation = Conversation::findOrFail($convId);

        // Simulate closing conversation with a closing owner in metadata
        $conversation->update([
            'status' => \App\Enums\ConversationStatus::CLOSED,
            'closed_at' => now()->subMinutes(10),
            'assigned_user_id' => null,
            'metadata' => [
                'last_assigned_user_id' => 888,
            ],
        ]);
        $this->assertEquals(888, $conversation->fresh()->closing_assigned_user_id);

        // Reopen existing conversation by calling /start again with same visitor_token
        $start2 = $this->withHeader('Authorization', "Bearer {$visitorToken}")
            ->postJson('/api/v1/public/webchat/conversations/start')
            ->assertStatus(201);

        $fresh = $conversation->fresh();

        // 1. Same conversation reused
        $this->assertSame($convId, $start2->json('data.conversation.id'));

        // 2. Closed status reopened to bot, closed_at reset to null
        $this->assertEquals(\App\Enums\ConversationStatus::BOT, $fresh->status);
        $this->assertNull($fresh->closed_at);
        $this->assertNull($fresh->assigned_user_id);

        // 3. metadata.last_assigned_user_id must be completely cleaned so closing owner is not stale
        $this->assertArrayNotHasKey('last_assigned_user_id', $fresh->metadata ?? []);
        $this->assertNull($fresh->closing_assigned_user_id);

        // 4. Entry flow was restarted and sent greeting
        $contents = array_column($start2->json('data.messages'), 'content');
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $convId,
            'sender_type' => 'system',
            'visibility' => 'internal',
            'content' => '── Sesión finalizada ──',
        ]);
        $this->assertNotContains('── Sesión finalizada ──', $contents, 'Internal session dividers must not be exposed to visitors.');
        $this->assertContains('Bienvenido al nuevo ciclo.', $contents);
    }

    public function test_visitor_sending_message_on_closed_conversation_reopens_same_conversation_and_restarts_entry_flow_idempotently(): void
    {
        // 1. Create Webchat Channel with Settings
        $channel = Channel::create([
            'name' => 'Webchat Inbound Reopen Channel',
            'type' => 'webchat',
            'is_active' => true,
        ]);

        $publicKey = 'wc_pub_'.Str::random(32);
        WebchatChannelSettings::create([
            'channel_id' => $channel->id,
            'public_key' => $publicKey,
            'allowed_origins' => ['http://localhost:3000'],
            'display_name' => 'Test Webchat Reopen',
            'accent_color' => '#2563EB',
            'launcher_position' => 'bottom-right',
        ]);

        // 2. Flow: START -> TEXT ("Bienvenido tras reapertura") -> QUESTION
        $flow = Flow::create([
            'name' => 'Reopen Inbound Flow',
            'status' => FlowStatus::PUBLISHED,
        ]);

        $version = FlowVersion::create([
            'flow_id' => $flow->id,
            'version_number' => 1,
            'status' => FlowVersionStatus::PUBLISHED,
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'name' => 'Start',
            'node_key' => 'start_node',
            'type' => FlowNodeType::START,
            'configuration' => [],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'name' => 'Greeting',
            'node_key' => 'text_greeting',
            'type' => FlowNodeType::MESSAGE,
            'configuration' => ['text' => 'Bienvenido tras reapertura.'],
        ]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'name' => 'Ask question',
            'node_key' => 'question_node',
            'type' => FlowNodeType::QUESTION,
            'configuration' => [
                'text' => '¿En qué podemos ayudarte?',
                'variable' => 'user_choice',
            ],
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_node',
            'target_node_key' => 'text_greeting',
        ]);

        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'text_greeting',
            'target_node_key' => 'question_node',
        ]);

        $flow->update(['active_version_id' => $version->id]);
        $channel->update(['entry_flow_id' => $flow->id]);

        // 3. Connect visitor session
        $embedRes = $this->postJson("/api/v1/public/webchat/{$publicKey}/embed", [
            'origin' => 'http://localhost:3000',
        ])->assertStatus(200);

        $embedToken = $embedRes->json('data.embed_token');

        $sessionRes = $this->postJson("/api/v1/public/webchat/{$publicKey}/sessions", [
            'embed_token' => $embedToken,
            'origin' => 'http://localhost:3000',
        ])->assertStatus(201);

        $visitorToken = $sessionRes->json('data.visitor_token');

        // Start initial conversation
        $start = $this->withHeader('Authorization', "Bearer {$visitorToken}")
            ->postJson('/api/v1/public/webchat/conversations/start')
            ->assertStatus(201);

        $convId = $start->json('data.conversation.id');
        $conversation = Conversation::findOrFail($convId);

        // Record an active execution
        $oldExecution = \App\Models\FlowExecution::create([
            'conversation_id' => $conversation->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'contact_id' => $conversation->contact_id,
            'status' => \App\Enums\FlowExecutionStatus::WAITING_INPUT,
            'current_node_id' => FlowNode::where('node_key', 'question_node')->value('id'),
            'started_at' => now()->subMinutes(15),
        ]);

        // Close the conversation with a closing owner in metadata
        $conversation->update([
            'status' => \App\Enums\ConversationStatus::CLOSED,
            'closed_at' => now()->subMinutes(10),
            'assigned_user_id' => null,
            'metadata' => [
                'last_assigned_user_id' => 999,
            ],
        ]);
        $this->assertEquals(999, $conversation->fresh()->closing_assigned_user_id);

        // 4. Send message directly on closed conversation
        $clientMessageId = (string) Str::uuid();
        $msgRes = $this->withHeader('Authorization', "Bearer {$visitorToken}")
            ->postJson('/api/v1/public/webchat/messages', [
                'content' => 'Hola, tengo una nueva consulta',
                'client_message_id' => $clientMessageId,
            ])
            ->assertStatus(201);

        // Asserts on returned payload
        $this->assertSame($convId, $msgRes->json('conversation.id'), 'Must reopen the same conversation ID');
        $this->assertFalse($msgRes->json('is_duplicate'));

        $fresh = $conversation->fresh();

        // Must reopen status, clear closed_at, and wipe stale closing owner
        $this->assertNull($fresh->closed_at);
        $this->assertNull($fresh->assigned_user_id);
        $this->assertArrayNotHasKey('last_assigned_user_id', $fresh->metadata ?? []);
        $this->assertNull($fresh->closing_assigned_user_id);

        // Old execution must have been cancelled
        $this->assertEquals(\App\Enums\FlowExecutionStatus::CANCELLED, $oldExecution->fresh()->status);

        // New entry flow execution started and sent greeting
        $latestMessages = $msgRes->json('messages');
        $contents = array_column($latestMessages, 'content');
        $this->assertContains('Bienvenido tras reapertura.', $contents);

        // 5. Idempotent retry with the exact same client_message_id
        $msgDup = $this->withHeader('Authorization', "Bearer {$visitorToken}")
            ->postJson('/api/v1/public/webchat/messages', [
                'content' => 'Hola, tengo una nueva consulta',
                'client_message_id' => $clientMessageId,
            ])
            ->assertStatus(200);

        $this->assertTrue($msgDup->json('is_duplicate'));

        // Ensure no extra flow executions were created on duplicate
        $activeExecutionsCount = \App\Models\FlowExecution::where('conversation_id', $convId)
            ->whereIn('status', [
                \App\Enums\FlowExecutionStatus::RUNNING,
                \App\Enums\FlowExecutionStatus::WAITING_INPUT,
            ])
            ->count();
        $this->assertEquals(1, $activeExecutionsCount);
    }
}
