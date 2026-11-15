<?php

namespace Tests\Unit;

use App\Enums\FlowNodeType;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowVersion;
use App\Services\FlowValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected FlowValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FlowValidator;
    }

    public function test_valid_flow_passes_validation(): void
    {
        $flow = Flow::create(['name' => 'Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'end_1',
        ]);

        $result = $this->validator->validate($version);

        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_flow_without_start_node_fails(): void
    {
        $flow = Flow::create(['name' => 'Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_1',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Msg',
            'configuration' => ['text' => 'Hola'],
        ]);

        $result = $this->validator->validate($version);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('exactamente un nodo de inicio', $result['errors'][0]);
    }

    public function test_flow_with_multiple_start_nodes_fails(): void
    {
        $flow = Flow::create(['name' => 'Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start 1',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_2',
            'type' => FlowNodeType::START,
            'name' => 'Start 2',
        ]);

        $result = $this->validator->validate($version);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('más de un nodo de inicio', $result['errors'][0]);
    }

    public function test_flow_with_non_existent_edge_target_fails(): void
    {
        $flow = Flow::create(['name' => 'Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start 1',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'non_existent_node',
        ]);

        $result = $this->validator->validate($version);

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('no corresponde a ningún nodo válido', $result['errors'][0]);
    }

    public function test_unreachable_node_fails_validation(): void
    {
        $flow = Flow::create(['name' => 'Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'orphan_node',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Orphan',
            'configuration' => ['text' => 'Hola'],
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'end_1',
        ]);

        $result = $this->validator->validate($version);

        $this->assertFalse($result['is_valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, 'inalcanzable desde el nodo de inicio')));
    }

    public function test_message_node_without_text_fails(): void
    {
        $flow = Flow::create(['name' => 'Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'msg_1',
            'type' => FlowNodeType::MESSAGE,
            'name' => 'Message Empty',
            'configuration' => ['text' => ''],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'msg_1',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'msg_1',
            'target_node_key' => 'end_1',
        ]);

        $result = $this->validator->validate($version);

        $this->assertFalse($result['is_valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, "debe contener el campo 'text'")));
    }

    public function test_question_node_missing_variable_fails(): void
    {
        $flow = Flow::create(['name' => 'Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'q_1',
            'type' => FlowNodeType::QUESTION,
            'name' => 'Question without variable',
            'configuration' => ['text' => '¿Cuál es tu nombre?'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'q_1',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'q_1',
            'target_node_key' => 'end_1',
        ]);

        $result = $this->validator->validate($version);

        $this->assertFalse($result['is_valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, 'debe especificar una variable válida')));
    }

    public function test_condition_node_missing_branches_fails(): void
    {
        $flow = Flow::create(['name' => 'Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'cond_1',
            'type' => FlowNodeType::CONDITION,
            'name' => 'Condition',
            'configuration' => ['variable' => 'edad', 'operator' => 'gte', 'value' => 18],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'cond_1',
        ]);
        // Only one edge without handle
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'cond_1',
            'target_node_key' => 'end_1',
            'condition_handle' => null,
        ]);

        $result = $this->validator->validate($version);

        $this->assertFalse($result['is_valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, "conexión de salida 'true'")));
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, "conexión de salida 'false'")));
    }

    public function test_condition_node_accepts_case_insensitive_and_alternate_handle_keys(): void
    {
        $flow = Flow::create(['name' => 'Condition Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'cond_1',
            'type' => FlowNodeType::CONDITION,
            'name' => 'Condition',
            'configuration' => ['variable' => 'activo', 'operator' => 'equals', 'value' => 'TRUE'],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End 1',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_2',
            'type' => FlowNodeType::END,
            'name' => 'End 2',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'cond_1',
        ]);
        // Uppercase TRUE and FALSE condition handles
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'cond_1',
            'target_node_key' => 'end_1',
            'condition_handle' => 'TRUE',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'cond_1',
            'target_node_key' => 'end_2',
            'condition_handle' => 'FALSE',
        ]);

        $result = $this->validator->validate($version);
        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_end_and_transfer_cannot_have_outgoing_edges(): void
    {
        $flow = Flow::create(['name' => 'Test Flow']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'transfer_1',
            'type' => FlowNodeType::TRANSFER_TO_AGENT,
            'name' => 'Transfer',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'transfer_1',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'transfer_1',
            'target_node_key' => 'end_1',
        ]);

        $result = $this->validator->validate($version);

        $this->assertFalse($result['is_valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, 'no puede tener conexiones salientes')));
    }

    public function test_file_node_validation_requires_url_and_single_outgoing_edge(): void
    {
        $flow = Flow::create(['name' => 'Test Flow File']);
        $version = FlowVersion::create(['flow_id' => $flow->id, 'version_number' => 1]);

        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'start_1',
            'type' => FlowNodeType::START,
            'name' => 'Start',
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'file_1',
            'type' => FlowNodeType::FILE,
            'name' => 'File Node',
            'configuration' => ['url' => ''],
        ]);
        FlowNode::create([
            'flow_version_id' => $version->id,
            'node_key' => 'end_1',
            'type' => FlowNodeType::END,
            'name' => 'End',
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'start_1',
            'target_node_key' => 'file_1',
        ]);

        // Missing url and missing outgoing edge
        $result = $this->validator->validate($version);
        $this->assertFalse($result['is_valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, "El nodo de archivo 'file_1' debe contener una URL válida")));
        $this->assertTrue(collect($result['errors'])->contains(fn ($err) => str_contains($err, "El nodo de archivo 'file_1' debe tener exactamente una conexión saliente")));

        // Now fix both url and edge
        FlowNode::where('node_key', 'file_1')->update([
            'configuration' => ['url' => 'https://example.com/doc.pdf', 'file_name' => 'doc.pdf'],
        ]);
        FlowEdge::create([
            'flow_version_id' => $version->id,
            'source_node_key' => 'file_1',
            'target_node_key' => 'end_1',
        ]);

        $validResult = $this->validator->validate($version->fresh());
        $this->assertTrue($validResult['is_valid']);
        $this->assertEmpty($validResult['errors']);
    }
}
