<?php

namespace Tests\Unit;

use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\VariableInterpolator;
use PHPUnit\Framework\TestCase;

class VariableInterpolatorTest extends TestCase
{
    protected VariableInterpolator $interpolator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->interpolator = new VariableInterpolator;
    }

    public function test_interpolates_existing_variables(): void
    {
        $context = new ExecutionContext([
            'nombre' => 'Luis',
            'edad' => 30,
        ]);

        $result = $this->interpolator->interpolate('Hola {{nombre}}, tienes {{ edad }} años.', $context);

        $this->assertEquals('Hola Luis, tienes 30 años.', $result['text']);
        $this->assertEquals(['nombre', 'edad'], $result['used_variables']);
        $this->assertEmpty($result['missing_variables']);
    }

    public function test_missing_variables_become_empty_string_without_error(): void
    {
        $context = new ExecutionContext([
            'nombre' => 'Ana',
        ]);

        $result = $this->interpolator->interpolate('Hola {{nombre}}, tu saldo es {{saldo}}.', $context);

        $this->assertEquals('Hola Ana, tu saldo es .', $result['text']);
        $this->assertEquals(['nombre'], $result['used_variables']);
        $this->assertEquals(['saldo'], $result['missing_variables']);
    }

    public function test_interpolates_nested_and_boolean_variables(): void
    {
        $context = new ExecutionContext([
            'usuario' => [
                'perfil' => ['activo' => true],
            ],
        ]);

        $result = $this->interpolator->interpolate('Estado: {{ usuario.perfil.activo }}', $context);

        $this->assertEquals('Estado: true', $result['text']);
        $this->assertEquals(['usuario.perfil.activo'], $result['used_variables']);
    }
}
