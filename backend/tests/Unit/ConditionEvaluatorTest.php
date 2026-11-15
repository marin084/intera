<?php

namespace Tests\Unit;

use App\Exceptions\ConditionEvaluationException;
use App\Services\Runtime\ConditionEvaluator;
use App\Services\Runtime\ExecutionContext;
use PHPUnit\Framework\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    protected ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ConditionEvaluator;
    }

    public function test_equals_and_not_equals(): void
    {
        $context = new ExecutionContext(['nombre' => 'Luis', 'edad' => 25]);

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'nombre',
            'operator' => 'equals',
            'value' => 'luis',
        ], $context));

        $this->assertFalse($this->evaluator->evaluate([
            'variable' => 'nombre',
            'operator' => 'equals',
            'value' => 'Carlos',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'edad',
            'operator' => 'equals',
            'value' => '25',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'nombre',
            'operator' => 'not_equals',
            'value' => 'Maria',
        ], $context));
    }

    public function test_boolean_evaluation_with_native_and_uppercase_strings(): void
    {
        $context = new ExecutionContext([
            'isActive' => true,
            'isBlocked' => false,
            'MaratonicaMode' => 'TRUE',
            'DebugMode' => 'FALSE',
        ]);

        // Native bool true matches 'TRUE', 'true', and native true
        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'isActive',
            'operator' => 'equals',
            'value' => 'TRUE',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => '$isActive',
            'operator' => 'equals',
            'value' => 'true',
        ], $context));

        $this->assertFalse($this->evaluator->evaluate([
            'variable' => 'isActive',
            'operator' => 'equals',
            'value' => 'FALSE',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'isActive',
            'operator' => 'not_equals',
            'value' => 'FALSE',
        ], $context));

        // Native bool false matches 'FALSE', 'false', and native false
        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'isBlocked',
            'operator' => 'equals',
            'value' => 'FALSE',
        ], $context));

        $this->assertFalse($this->evaluator->evaluate([
            'variable' => '$isBlocked',
            'operator' => 'equals',
            'value' => 'TRUE',
        ], $context));

        // Uppercase string 'TRUE' matches 'TRUE', true
        $this->assertTrue($this->evaluator->evaluate([
            'variable' => '$MaratonicaMode',
            'operator' => 'equals',
            'value' => 'TRUE',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'MaratonicaMode',
            'operator' => 'equals',
            'value' => true,
        ], $context));

        // Uppercase string 'FALSE' matches 'FALSE', false
        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'DebugMode',
            'operator' => 'equals',
            'value' => 'FALSE',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => '$DebugMode',
            'operator' => 'equals',
            'value' => false,
        ], $context));
    }

    public function test_string_operators(): void
    {
        $context = new ExecutionContext(['email' => 'juan.perez@example.com']);

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'email',
            'operator' => 'contains',
            'value' => 'perez',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'email',
            'operator' => 'starts_with',
            'value' => 'juan',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'email',
            'operator' => 'ends_with',
            'value' => '.com',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'email',
            'operator' => 'not_contains',
            'value' => 'gmail',
        ], $context));
    }

    public function test_numeric_comparisons(): void
    {
        $context = new ExecutionContext(['edad' => 18]);

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'edad',
            'operator' => 'gte',
            'value' => 18,
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'edad',
            'operator' => 'lte',
            'value' => 18,
        ], $context));

        $this->assertFalse($this->evaluator->evaluate([
            'variable' => 'edad',
            'operator' => 'gt',
            'value' => 18,
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'edad',
            'operator' => 'lt',
            'value' => 21,
        ], $context));
    }

    public function test_exists_and_not_exists(): void
    {
        $context = new ExecutionContext(['cliente' => 'si']);

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'cliente',
            'operator' => 'exists',
        ], $context));

        $this->assertTrue($this->evaluator->evaluate([
            'variable' => 'telefono',
            'operator' => 'not_exists',
        ], $context));
    }

    public function test_invalid_operator_throws_exception(): void
    {
        $this->expectException(ConditionEvaluationException::class);
        $context = new ExecutionContext(['edad' => 18]);

        $this->evaluator->evaluate([
            'variable' => 'edad',
            'operator' => 'unsupported_op',
            'value' => 18,
        ], $context);
    }

    public function test_missing_variable_throws_exception(): void
    {
        $this->expectException(ConditionEvaluationException::class);
        $context = new ExecutionContext([]);

        $this->evaluator->evaluate([
            'variable' => '',
            'operator' => 'equals',
            'value' => 'test',
        ], $context);
    }

    public function test_multi_rule_and_logic(): void
    {
        $context = new ExecutionContext(['status' => 'approved', 'score' => 95]);

        $config = [
            'logic' => 'and',
            'rules' => [
                ['variable' => 'status', 'operator' => 'equals', 'value' => 'approved'],
                ['variable' => 'score', 'operator' => 'gte', 'value' => 90],
            ],
        ];

        $this->assertTrue($this->evaluator->evaluate($config, $context));

        // When one fails, AND fails
        $contextFail = new ExecutionContext(['status' => 'approved', 'score' => 80]);
        $this->assertFalse($this->evaluator->evaluate($config, $contextFail));
    }

    public function test_multi_rule_or_logic(): void
    {
        $context = new ExecutionContext(['vip' => 'true', 'score' => 50]);

        $config = [
            'logic' => 'or',
            'rules' => [
                ['variable' => 'vip', 'operator' => 'equals', 'value' => 'true'],
                ['variable' => 'score', 'operator' => 'gte', 'value' => 90],
            ],
        ];

        $this->assertTrue($this->evaluator->evaluate($config, $context));

        $contextBothFail = new ExecutionContext(['vip' => 'false', 'score' => 50]);
        $this->assertFalse($this->evaluator->evaluate($config, $contextBothFail));
    }

    public function test_compare_with_dynamic_variable(): void
    {
        $context = new ExecutionContext(['input_val' => 'abc', 'expected_val' => 'abc']);

        $config = [
            'variable' => 'input_val',
            'operator' => 'equals',
            'compare_type' => 'variable',
            'value' => 'expected_val',
        ];

        $this->assertTrue($this->evaluator->evaluate($config, $context));

        $contextMismatch = new ExecutionContext(['input_val' => 'abc', 'expected_val' => 'xyz']);
        $this->assertFalse($this->evaluator->evaluate($config, $contextMismatch));
    }
}
