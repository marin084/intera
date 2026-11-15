<?php

namespace App\Services\Runtime\Handlers;

use App\Enums\FlowFailureCode;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;
use App\Services\Runtime\VariableInterpolator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HttpRequestNodeHandler implements NodeHandler
{
    protected const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'api-key',
        'apikey',
        'cookie',
        'set-cookie',
        'token',
        'secret',
        'x-auth-token',
    ];

    public function __construct(
        protected VariableInterpolator $interpolator
    ) {}

    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $rawUrl = $config['url'] ?? '';
        $method = strtoupper($config['method'] ?? 'GET');

        if (empty($rawUrl) || ! is_string($rawUrl)) {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo HTTP '{$node->node_key}' debe contener una URL válida."
            );
        }

        // Interpolate URL
        $urlInterpolated = $this->interpolator->interpolate($rawUrl, $context);
        $url = trim($urlInterpolated['text']);

        // Check for mock response in testing/demo
        if (isset($config['mock_response'])) {
            return $this->processResponse(
                $node,
                $context,
                $config,
                $config['mock_response']['status'] ?? 200,
                $config['mock_response']['data'] ?? [],
                'MOCK'
            );
        }

        // Safe mock in simulation environment if no explicit mock provided
        $isSimulation = (bool) ($execution->conversation?->is_simulation ?? false);
        if ($isSimulation) {
            Log::info("[FlowSimulator] HttpRequestNodeHandler: simulando respuesta para {$method} {$url}");

            return $this->processResponse(
                $node,
                $context,
                $config,
                200,
                [
                    'simulated' => true,
                    'method' => $method,
                    'url' => $url,
                    'status' => 'success',
                ],
                'SIMULATED'
            );
        }

        // SSRF Validation
        $ssrfError = $this->validateSsrfSafeUrl($url);
        if ($ssrfError !== null) {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "Seguridad HTTP (SSRF): {$ssrfError}"
            );
        }

        // Prepare Headers & Redacted Headers for diagnostics
        $headers = [];
        $redactedHeaders = [];
        if (! empty($config['headers']) && is_array($config['headers'])) {
            foreach ($config['headers'] as $headerKey => $headerValue) {
                if (is_array($headerValue)) {
                    $headerKey = $headerValue['key'] ?? $headerKey;
                    $headerValue = $headerValue['value'] ?? '';
                }
                if (is_string($headerValue)) {
                    $interp = $this->interpolator->interpolate($headerValue, $context);
                    $finalVal = $interp['text'];
                } else {
                    $finalVal = (string) $headerValue;
                }

                $headers[$headerKey] = $finalVal;
                $redactedHeaders[$headerKey] = $this->isSensitiveHeader((string) $headerKey)
                    ? '***REDACTED***'
                    : $finalVal;
            }
        }

        // Prepare Body
        $body = null;
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $rawBody = $config['body'] ?? null;
            if (is_string($rawBody) && $rawBody !== '') {
                $interp = $this->interpolator->interpolate($rawBody, $context);
                $decoded = json_decode($interp['text'], true);
                $body = json_last_error() === JSON_ERROR_NONE ? $decoded : $interp['text'];
            } elseif (is_array($rawBody)) {
                $body = $this->interpolateArray($rawBody, $context);
            }
        }

        $defaultTimeout = (int) config('flows.http.default_timeout', 5);
        $maxTimeout = (int) config('flows.http.max_timeout', 15);
        $timeout = min(max((int) ($config['timeout'] ?? $defaultTimeout), 1), $maxTimeout);
        $maxBytes = (int) config('flows.http.max_response_bytes', 2097152); // Default 2 MB

        try {
            $request = Http::timeout($timeout)
                ->withHeaders($headers)
                ->withoutRedirecting();

            $response = match ($method) {
                'POST' => is_array($body) ? $request->post($url, $body) : $request->withBody($body ?? '', 'application/json')->post($url),
                'PUT' => is_array($body) ? $request->put($url, $body) : $request->withBody($body ?? '', 'application/json')->put($url),
                'PATCH' => is_array($body) ? $request->patch($url, $body) : $request->withBody($body ?? '', 'application/json')->patch($url),
                'DELETE' => $request->delete($url, is_array($body) ? $body : []),
                default => $request->get($url),
            };

            // Enforce response payload size limits
            $bodyContent = $response->body();
            if (strlen($bodyContent) > $maxBytes) {
                $err = "El tamaño de la respuesta ({strlen($bodyContent)} bytes) superó el límite permitido de {$maxBytes} bytes.";
                Log::warning("[HttpRequestNodeHandler] {$err} para nodo {$node->node_key}");

                if (! empty($config['allow_failure_branch'])) {
                    return NodeExecutionResult::completed(
                        output: ['error' => $err, 'status_code' => 413],
                        outgoingHandle: 'fail'
                    );
                }

                return NodeExecutionResult::failed(FlowFailureCode::HTTP_REQUEST_ERROR->value, $err);
            }

            $statusCode = $response->status();
            $responseData = $response->json() ?? $bodyContent;

            return $this->processResponse($node, $context, $config, $statusCode, $responseData, $method, $redactedHeaders);
        } catch (Throwable $e) {
            Log::warning("[HttpRequestNodeHandler] Error en petición HTTP para nodo {$node->node_key}: ".$e->getMessage());

            if (! empty($config['allow_failure_branch'])) {
                return NodeExecutionResult::completed(
                    output: ['error' => $e->getMessage(), 'status_code' => 500],
                    outgoingHandle: 'fail'
                );
            }

            return NodeExecutionResult::failed(
                FlowFailureCode::HTTP_REQUEST_ERROR->value,
                "Error en petición HTTP '{$node->node_key}': ".$e->getMessage()
            );
        }
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        return NodeExecutionResult::failed(
            FlowFailureCode::EXECUTION_STATE_ERROR->value,
            'El nodo HTTP no espera interacción directa de mensajes.'
        );
    }

    protected function processResponse(
        FlowNode $node,
        ExecutionContext $context,
        array $config,
        int $statusCode,
        mixed $responseData,
        string $method,
        array $redactedHeaders = []
    ): NodeExecutionResult {
        // Save status code if configured
        if (! empty($config['save_status_code_to'])) {
            $context->set($config['save_status_code_to'], $statusCode);
        }

        // Save entire response body if configured
        if (! empty($config['save_response_body_to'])) {
            $context->set($config['save_response_body_to'], $responseData);
        }

        // Process response mapping (json_path -> target_variable)
        $mappings = $config['response_mapping'] ?? [];
        $extracted = [];

        if (is_array($mappings) && is_array($responseData)) {
            foreach ($mappings as $mapping) {
                $path = $mapping['json_path'] ?? ($mapping['path'] ?? null);
                $targetVar = $mapping['variable_name'] ?? ($mapping['variable'] ?? null);

                if (! empty($path) && ! empty($targetVar)) {
                    $val = data_get($responseData, $path);
                    $context->set($targetVar, $val);
                    $extracted[$targetVar] = $val;
                }
            }
        }

        $isSuccess = $statusCode >= 200 && $statusCode < 300;
        $outgoingHandle = $isSuccess ? 'success' : 'fail';

        return NodeExecutionResult::completed(
            output: [
                'status_code' => $statusCode,
                'extracted_variables' => $extracted,
            ],
            outgoingHandle: $outgoingHandle,
            metadata: [
                'method' => $method,
                'status_code' => $statusCode,
                'headers' => $redactedHeaders,
            ]
        );
    }

    protected function interpolateArray(array $array, ExecutionContext $context): array
    {
        $result = [];
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $result[$key] = $this->interpolateArray($val, $context);
            } elseif (is_string($val)) {
                $interp = $this->interpolator->interpolate($val, $context);
                $result[$key] = $interp['text'];
            } else {
                $result[$key] = $val;
            }
        }

        return $result;
    }

    /**
     * Validates that a URL does not point to internal/reserved private IPs or metadata endpoints (SSRF protection),
     * unless explicitly permitted in config('flows.http.allowed_hosts').
     */
    protected function validateSsrfSafeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
            return 'URL con formato inválido.';
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return "Protocolo '{$scheme}' no permitido. Solo se admite http y https.";
        }

        $host = strtolower($parts['host']);

        // Check enterprise allowed hosts list
        $allowedHosts = (array) config('flows.http.allowed_hosts', []);
        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower(trim((string) $allowedHost));
            if ($allowedHost !== '' && ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost))) {
                return null;
            }
        }

        // Block literal localhost and standard loopbacks immediately
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '0.0.0.0' || $host === '[::1]') {
            return 'Acceso a localhost y direcciones loopback denegado por política de seguridad.';
        }

        // Check if host is direct IP address
        $unbracketedHost = trim($host, '[]');
        if (filter_var($unbracketedHost, FILTER_VALIDATE_IP)) {
            if ($this->isPrivateOrReservedIp($unbracketedHost)) {
                return "La dirección destino ({$unbracketedHost}) es una red interna, privada o reservada no autorizada.";
            }

            return null;
        }

        // In unit/feature testing, allow standard mock domains
        if (app()->environment('testing') && (str_contains($host, 'example.com') || str_contains($host, 'conexa.test'))) {
            return null;
        }

        // Resolve DNS and check all resolved IPs
        $resolvedIps = [];
        $ips = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($ips)) {
            foreach ($ips as $record) {
                if (! empty($record['ip'])) {
                    $resolvedIps[] = $record['ip'];
                } elseif (! empty($record['ipv6'])) {
                    $resolvedIps[] = $record['ipv6'];
                }
            }
        }

        if (empty($resolvedIps)) {
            $ip = @gethostbyname($host);
            if ($ip && $ip !== $host) {
                $resolvedIps[] = $ip;
            }
        }

        if (empty($resolvedIps)) {
            // Unresolvable host
            return "No se pudo resolver el host DNS '{$host}'.";
        }

        foreach ($resolvedIps as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                return "La dirección IP resuelta ({$ip}) corresponde a una red interna o reservada no autorizada.";
            }
        }

        return null;
    }

    protected function isPrivateOrReservedIp(string $ip): bool
    {
        $ip = trim($ip, '[]');

        // Check IPv4-mapped IPv6 (e.g. ::ffff:127.0.0.1)
        if (str_starts_with($ip, '::ffff:')) {
            $ipv4 = substr($ip, 7);
            if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $this->isPrivateOrReservedIp($ipv4);
            }
        }

        // Check link-local / cloud metadata (169.254.x.x, 100.100.100.200)
        if (str_starts_with($ip, '169.254.') || $ip === '100.100.100.200') {
            return true;
        }

        // Loopback 127.x.x.x or 0.x.x.x
        if (str_starts_with($ip, '127.') || str_starts_with($ip, '0.')) {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    protected function isSensitiveHeader(string $headerKey): bool
    {
        return in_array(strtolower(trim($headerKey)), self::SENSITIVE_HEADERS, true);
    }
}
