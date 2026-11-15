<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
            'version' => config('app.version', '1.0.0'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [];
        $isReady = true;

        // Database check
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (Throwable $e) {
            $checks['database'] = 'error';
            $isReady = false;
        }

        // Redis check (required when configured in staging/production)
        $redisHost = config('database.redis.default.host');
        if (app()->environment() !== 'testing' && $redisHost && $redisHost !== 'null') {
            try {
                Redis::connection()->ping();
                $checks['redis'] = 'ok';
            } catch (Throwable $e) {
                $checks['redis'] = 'error';
                $isReady = false;
            }
        } else {
            $checks['redis'] = 'skipped';
        }

        // Realtime Reverb check (informational in ready check)
        $realtimeCheck = $this->checkRealtimeReverb();
        $checks['realtime'] = $realtimeCheck['status'];

        // Media Storage / APP_URL public accessibility check
        $appUrl = (string) config('app.url');
        $checks['media_url'] = $this->checkMediaUrl($appUrl);

        return response()->json([
            'status' => $isReady ? 'ready' : 'unhealthy',
            'version' => config('app.version', '1.0.0'),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $isReady ? 200 : 503);
    }

    public function realtime(): JsonResponse
    {
        $check = $this->checkRealtimeReverb();
        $isOk = $check['status'] === 'ok';

        return response()->json([
            'status' => $check['status'],
            'service' => 'reverb',
            'details' => $check['details'] ?? null,
            'timestamp' => now()->toIso8601String(),
        ], $isOk ? 200 : 503);
    }

    protected function checkRealtimeReverb(): array
    {
        if (app()->environment() === 'testing') {
            return ['status' => 'ok', 'details' => 'testing_environment'];
        }

        $host = config('broadcasting.connections.reverb.options.host') ?: 'reverb';
        $port = (int) (config('broadcasting.connections.reverb.options.port') ?: 8080);

        $fp = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($fp) {
            fclose($fp);

            return ['status' => 'ok'];
        }

        return [
            'status' => 'unavailable',
            'details' => "Cannot connect to Reverb at {$host}:{$port} ({$errno}: {$errstr})",
        ];
    }

    public function checkMediaUrl(string $appUrl): string
    {
        $isHttps = str_starts_with(strtolower($appUrl), 'https://');
        $isLocalHost = str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1');

        if (app()->environment('production')) {
            if (! $isHttps || $isLocalHost) {
                return 'warning: APP_URL is not public HTTPS, Meta media downloads will fail';
            }

            return 'ok';
        }

        // In non-production (local/testing), report local_dev without failing readiness
        return $isHttps && ! $isLocalHost ? 'ok' : 'local_dev';
    }
}
