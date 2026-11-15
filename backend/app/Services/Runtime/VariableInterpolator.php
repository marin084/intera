<?php

namespace App\Services\Runtime;

class VariableInterpolator
{
    /**
     * Interpolates variables in a template string using the execution context.
     *
     * @return array{text: string, used_variables: array<string>, missing_variables: array<string>}
     */
    public function interpolate(string $template, ExecutionContext $context): array
    {
        $usedVariables = [];
        $missingVariables = [];

        $pattern = '/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/';

        $text = preg_replace_callback($pattern, function (array $matches) use ($context, &$usedVariables, &$missingVariables) {
            $variableName = $matches[1];

            if ($context->has($variableName)) {
                $usedVariables[] = $variableName;
                $value = $context->get($variableName);

                if (is_array($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }

                return (string) $value;
            }

            $missingVariables[] = $variableName;

            return '';
        }, $template);

        return [
            'text' => $text ?? '',
            'used_variables' => array_values(array_unique($usedVariables)),
            'missing_variables' => array_values(array_unique($missingVariables)),
        ];
    }
}
