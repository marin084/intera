<?php

namespace App\Services\Runtime;

use App\Exceptions\ConditionEvaluationException;

class ConditionEvaluator
{
    public const ALLOWED_OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'starts_with',
        'ends_with',
        'gt',
        'gte',
        'lt',
        'lte',
        'exists',
        'not_exists',
        'is_empty',
        'not_empty',
    ];

    /**
     * Evaluates a condition configuration against the execution context.
     * Supports both single-rule ({variable, operator, value}) and multi-rule ({logic: 'and'|'or', rules: [...]}).
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ConditionEvaluationException
     */
    public function evaluate(array $config, ExecutionContext $context): bool
    {
        // Support multi-rule condition
        if (isset($config['rules']) && is_array($config['rules']) && ! empty($config['rules'])) {
            $logic = strtolower($config['logic'] ?? 'and');
            $rules = $config['rules'];

            if ($logic === 'or') {
                foreach ($rules as $rule) {
                    if ($this->evaluateSingleRule($rule, $context)) {
                        return true;
                    }
                }

                return false;
            }

            // Default: 'and'
            foreach ($rules as $rule) {
                if (! $this->evaluateSingleRule($rule, $context)) {
                    return false;
                }
            }

            return true;
        }

        return $this->evaluateSingleRule($config, $context);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluateSingleRule(array $config, ExecutionContext $context): bool
    {
        $variable = ltrim(trim((string) ($config['variable'] ?? '')), '$');
        $operator = $config['operator'] ?? null;
        $expectedValue = $config['value'] ?? null;

        if (empty($variable)) {
            throw new ConditionEvaluationException('La condición debe especificar una variable válida.');
        }

        if (empty($operator) || ! in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new ConditionEvaluationException("Operador de condición no válido o no permitido: '{$operator}'.");
        }

        // Check if expected value is compared against another variable
        $compareType = $config['compare_type'] ?? 'static';
        if ($compareType === 'variable' && is_string($expectedValue)) {
            $expectedValue = $context->get(trim($expectedValue));
        } elseif (is_string($expectedValue) && preg_match('/^\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}$/', $expectedValue, $m)) {
            $expectedValue = $context->get($m[1]);
        }

        $exists = $context->has($variable);
        $actualValue = $context->get($variable);

        if ($operator === 'exists') {
            return $exists && $actualValue !== null && $actualValue !== '';
        }

        if ($operator === 'not_exists') {
            return ! $exists || $actualValue === null || $actualValue === '';
        }

        if ($operator === 'is_empty') {
            return empty($actualValue);
        }

        if ($operator === 'not_empty') {
            return ! empty($actualValue);
        }

        // If variable is missing and operator requires comparison, treat missing value as null/empty
        if (! $exists) {
            $actualValue = null;
        }

        return match ($operator) {
            'equals' => $this->evaluateEquals($actualValue, $expectedValue),
            'not_equals' => ! $this->evaluateEquals($actualValue, $expectedValue),
            'contains' => $this->evaluateContains($actualValue, $expectedValue),
            'not_contains' => ! $this->evaluateContains($actualValue, $expectedValue),
            'starts_with' => $this->evaluateStartsWith($actualValue, $expectedValue),
            'ends_with' => $this->evaluateEndsWith($actualValue, $expectedValue),
            'gt' => $this->evaluateComparison($actualValue, $expectedValue, fn ($a, $b) => $a > $b),
            'gte' => $this->evaluateComparison($actualValue, $expectedValue, fn ($a, $b) => $a >= $b),
            'lt' => $this->evaluateComparison($actualValue, $expectedValue, fn ($a, $b) => $a < $b),
            'lte' => $this->evaluateComparison($actualValue, $expectedValue, fn ($a, $b) => $a <= $b),
            default => throw new ConditionEvaluationException("Operador desconocido: {$operator}"),
        };
    }

    protected function evaluateEquals(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual == (float) $expected;
        }

        $isActualBool = is_bool($actual) || (is_string($actual) && in_array(strtolower(trim($actual)), ['true', 'false'], true));
        $isExpectedBool = is_bool($expected) || (is_string($expected) && in_array(strtolower(trim($expected)), ['true', 'false'], true));

        if ($isActualBool || $isExpectedBool) {
            $actualBool = filter_var($actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $expectedBool = filter_var($expected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($actualBool !== null && $expectedBool !== null) {
                return $actualBool === $expectedBool;
            }

            return false;
        }

        return mb_strtolower(trim((string) $actual)) === mb_strtolower(trim((string) $expected));
    }

    protected function evaluateContains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            foreach ($actual as $item) {
                if ($this->evaluateEquals($item, $expected)) {
                    return true;
                }
            }

            return false;
        }

        return str_contains(
            mb_strtolower((string) $actual),
            mb_strtolower((string) $expected)
        );
    }

    protected function evaluateStartsWith(mixed $actual, mixed $expected): bool
    {
        return str_starts_with(
            mb_strtolower((string) $actual),
            mb_strtolower((string) $expected)
        );
    }

    protected function evaluateEndsWith(mixed $actual, mixed $expected): bool
    {
        return str_ends_with(
            mb_strtolower((string) $actual),
            mb_strtolower((string) $expected)
        );
    }

    /**
     * @param  callable(float, float): bool  $comparator
     */
    protected function evaluateComparison(mixed $actual, mixed $expected, callable $comparator): bool
    {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        return $comparator((float) $actual, (float) $expected);
    }
}
