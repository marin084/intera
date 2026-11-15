<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Services\Runtime\ConditionEvaluator;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\VariableInterpolator;
use PHPUnit\Framework\TestCase;

class ExecutionContextTest extends TestCase
{
    public function test_alias_normalization_from_array(): void
    {
        $context = ExecutionContext::fromArray(['var_nombre' => 'Luis']);

        $this->assertTrue($context->has('visitor_name'));
        $this->assertTrue($context->has('nombre'));
        $this->assertTrue($context->has('name'));
        $this->assertTrue($context->has('var_nombre'));

        $this->assertSame('Luis', $context->get('visitor_name'));
        $this->assertSame('Luis', $context->get('nombre'));
        $this->assertSame('Luis', $context->get('name'));
        $this->assertSame('Luis', $context->get('var_nombre'));
    }

    public function test_setting_visitor_name_populates_all_aliases(): void
    {
        $context = new ExecutionContext();
        $context->set('visitor_name', 'Luis Fernando');

        $this->assertSame('Luis Fernando', $context->get('visitor_name'));
        $this->assertSame('Luis Fernando', $context->get('nombre'));
        $this->assertSame('Luis Fernando', $context->get('name'));
        $this->assertSame('Luis Fernando', $context->get('var_nombre'));
    }

    public function test_setting_nombre_populates_all_aliases(): void
    {
        $context = new ExecutionContext();
        $context->set('nombre', 'Carlos');

        $this->assertSame('Carlos', $context->get('visitor_name'));
        $this->assertSame('Carlos', $context->get('nombre'));
        $this->assertSame('Carlos', $context->get('name'));
        $this->assertSame('Carlos', $context->get('var_nombre'));
    }

    public function test_keys_with_dollar_prefix_resolve_seamlessly(): void
    {
        $context = new ExecutionContext(['visitor_name' => 'Luis']);

        $this->assertTrue($context->has('$visitor_name'));
        $this->assertTrue($context->has('$var_nombre'));
        $this->assertTrue($context->has('$nombre'));

        $this->assertSame('Luis', $context->get('$visitor_name'));
        $this->assertSame('Luis', $context->get('$var_nombre'));
        $this->assertSame('Luis', $context->get('$nombre'));
    }

    public function test_interpolation_with_aliases(): void
    {
        $context = new ExecutionContext(['nombre' => 'Luis']);
        $interpolator = new VariableInterpolator();

        $result1 = $interpolator->interpolate('Hola {{visitor_name}}!', $context);
        $this->assertSame('Hola Luis!', $result1['text']);

        $result2 = $interpolator->interpolate('Gracias {{var_nombre}}, anotado.', $context);
        $this->assertSame('Gracias Luis, anotado.', $result2['text']);
    }

    public function test_condition_evaluation_with_aliases_and_dollar(): void
    {
        $context = new ExecutionContext(['nombre' => 'Luis']);
        $evaluator = new ConditionEvaluator();

        // Single rule with visitor_name
        $this->assertTrue($evaluator->evaluate([
            'variable' => 'visitor_name',
            'operator' => 'not_empty',
        ], $context));

        // Single rule with $var_nombre
        $this->assertTrue($evaluator->evaluate([
            'variable' => '$var_nombre',
            'operator' => 'exists',
        ], $context));

        // Multi rule OR logic
        $this->assertTrue($evaluator->evaluate([
            'logic' => 'or',
            'rules' => [
                ['variable' => 'visitor_name', 'operator' => 'not_empty'],
                ['variable' => 'nombre', 'operator' => 'not_empty'],
            ],
        ], $context));
    }
}
