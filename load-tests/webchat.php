<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'load';
$profile = $argv[2] ?? 'ramp';
$baseUrl = getenv('BASE_URL') ?: 'http://127.0.0.1:8000';
$origin = getenv('ORIGIN') ?: 'http://loadtest.local';
$publicKey = getenv('WEBCHAT_PUBLIC_KEY') ?: 'wc_loadtest_only_not_for_production';
$sessionCount = $mode === 'smoke' ? 1 : (int) (getenv('LOADTEST_SESSION_COUNT') ?: 250);

if (! in_array($mode, ['smoke', 'load'], true) || ! in_array($profile, ['ramp', 'sweep', 'capacity'], true)) {
    fwrite(STDERR, "Usage: php webchat.php [smoke|load] [ramp|sweep|capacity]\n");
    exit(2);
}

if ($baseUrl !== 'http://127.0.0.1:8000') {
    fwrite(STDERR, "Safety guard: BASE_URL must use the isolated backend loopback.\n");
    exit(2);
}

function postJson(string $url, array $payload, ?string $visitorToken = null): array
{
    global $origin;

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Origin: '.$origin,
        'Connection: keep-alive',
    ];

    if ($visitorToken !== null) {
        $headers[] = 'Authorization: Bearer '.$visitorToken;
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
        CURLOPT_TIMEOUT_MS => 10000,
        CURLOPT_NOSIGNAL => true,
    ]);

    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $durationMs = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
    curl_close($handle);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
        'duration_ms' => $durationMs,
    ];
}

function requireSuccess(array $response, array $allowedStatuses, string $label): array
{
    if ($response['error'] !== '' || ! in_array($response['status'], $allowedStatuses, true)) {
        $detail = $response['error'] ?: substr($response['body'], 0, 400);
        throw new RuntimeException(sprintf('%s failed (HTTP %d): %s', $label, $response['status'], $detail));
    }

    $decoded = json_decode($response['body'], true);
    if (! is_array($decoded)) {
        throw new RuntimeException($label.' returned invalid JSON.');
    }

    return $decoded;
}

function containsBotReply(array $messages): bool
{
    foreach ($messages as $message) {
        if (($message['sender_type'] ?? null) === 'bot' && ($message['direction'] ?? null) === 'outbound') {
            return true;
        }
    }

    return false;
}

function prepareSessions(int $count, string $baseUrl, string $publicKey): array
{
    $embed = requireSuccess(
        postJson($baseUrl.'/api/v1/public/webchat/'.$publicKey.'/embed', []),
        [200],
        'Webchat embed handshake'
    );
    $embedToken = $embed['data']['embed_token'] ?? null;
    if (! is_string($embedToken) || $embedToken === '') {
        throw new RuntimeException('Webchat did not return an embed token.');
    }

    $sessions = [];
    for ($index = 1; $index <= $count; $index++) {
        $created = requireSuccess(
            postJson($baseUrl.'/api/v1/public/webchat/'.$publicKey.'/sessions', ['embed_token' => $embedToken]),
            [201],
            sprintf('Create visitor session %d/%d', $index, $count)
        );
        $visitorToken = $created['data']['visitor_token'] ?? null;
        if (! is_string($visitorToken) || $visitorToken === '') {
            throw new RuntimeException(sprintf('Session %d did not include a visitor token.', $index));
        }

        $started = requireSuccess(
            postJson($baseUrl.'/api/v1/public/webchat/conversations/start', [], $visitorToken),
            [200, 201],
            sprintf('Start conversation %d/%d', $index, $count)
        );
        if (! containsBotReply($started['data']['messages'] ?? [])) {
            throw new RuntimeException(sprintf('Conversation %d did not include the initial bot reply.', $index));
        }

        $sessions[] = $visitorToken;
    }

    return $sessions;
}

function rateAt(float $elapsedSeconds, array $stages): float
{
    $cursor = 0.0;
    foreach ($stages as [$duration, $fromRate, $toRate]) {
        if ($elapsedSeconds <= $cursor + $duration) {
            $progress = max(0.0, min(1.0, ($elapsedSeconds - $cursor) / $duration));

            return $fromRate + (($toRate - $fromRate) * $progress);
        }
        $cursor += $duration;
    }

    return 0.0;
}

function buildArrivalSchedule(array $stages): array
{
    $arrivals = [];
    $stageOffset = 0.0;

    foreach ($stages as $stageIndex => [$duration, $startRate, $endRate]) {
        $arrivalCount = (int) round((($startRate + $endRate) / 2) * $duration);
        $rateSlope = ($endRate - $startRate) / $duration;

        for ($arrival = 0; $arrival < $arrivalCount; $arrival++) {
            if (abs($rateSlope) < 0.000001) {
                $withinStage = $startRate > 0 ? $arrival / $startRate : 0.0;
            } else {
                $halfSlope = $rateSlope / 2;
                $discriminant = ($startRate * $startRate) + (4 * $halfSlope * $arrival);
                $withinStage = (-$startRate + sqrt(max(0.0, $discriminant))) / (2 * $halfSlope);
            }

            $rateAtArrival = $startRate + ($rateSlope * $withinStage);
            $arrivals[] = [$stageOffset + $withinStage, $stageIndex, max(0.001, $rateAtArrival)];
        }

        $stageOffset += $duration;
    }

    return $arrivals;
}

function percentile(array $values, float $percentile): ?float
{
    if ($values === []) {
        return null;
    }

    sort($values, SORT_NUMERIC);
    $index = (int) ceil(($percentile / 100) * count($values)) - 1;

    return round((float) $values[max(0, $index)], 2);
}

try {
    $setupStartedAt = microtime(true);
    $sessions = prepareSessions($sessionCount, $baseUrl, $publicKey);
    $setupDuration = microtime(true) - $setupStartedAt;

    if ($mode === 'smoke') {
        $message = requireSuccess(
            postJson($baseUrl.'/api/v1/public/webchat/messages', [
                'content' => 'Webchat isolated load-test smoke check',
                'client_message_id' => sprintf('00000000-0000-4000-8000-%012d', random_int(0, 999999999999)),
            ], $sessions[0]),
            [201],
            'Post inbound Webchat message'
        );
        $hasReply = containsBotReply($message['messages'] ?? []);
        $report = [
            'mode' => 'smoke',
            'passed' => $hasReply,
            'session_count' => count($sessions),
            'setup_duration_seconds' => round($setupDuration, 3),
            'message_status' => 201,
            'bot_reply_received' => $hasReply,
        ];
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        exit($hasReply ? 0 : 1);
    }

    $stages = match ($profile) {
        'sweep' => [
            [45.0, 10.0, 10.0],
            [45.0, 25.0, 25.0],
            [45.0, 50.0, 50.0],
            [45.0, 75.0, 75.0],
            [45.0, 100.0, 100.0],
            [15.0, 100.0, 0.0],
        ],
        'capacity' => [
            [45.0, 25.0, 25.0],
            [45.0, 30.0, 30.0],
            [45.0, 35.0, 35.0],
            [45.0, 40.0, 40.0],
            [15.0, 40.0, 0.0],
        ],
        default => [
            [30.0, 1.0, 10.0],
            [30.0, 10.0, 25.0],
            [30.0, 25.0, 50.0],
            [30.0, 50.0, 75.0],
            [30.0, 75.0, 100.0],
            [120.0, 100.0, 100.0],
            [15.0, 100.0, 0.0],
        ],
    };
    $profileDuration = array_sum(array_column($stages, 0));
    $arrivalSchedule = buildArrivalSchedule($stages);
    $multi = curl_multi_init();
    $active = [];
    $latencies = [];
    $failures = 0;
    $droppedAtConcurrencyLimit = 0;
    $schedulerSkipped = 0;
    $failureSamples = [];
    $statusCounts = [];
    $stageStats = [];
    foreach ($stages as $index => [$duration, $fromRate, $toRate]) {
        $stageStats[] = [
            'stage' => $index + 1,
            'duration_seconds' => $duration,
            'target_start_requests_per_second' => $fromRate,
            'target_end_requests_per_second' => $toRate,
            'requests_launched' => 0,
            'dropped_at_concurrency_limit' => 0,
            'skipped_by_scheduler' => 0,
            'requests_successful' => 0,
            'request_failures' => 0,
            'latencies_ms' => [],
        ];
    }
    $sequence = 0;
    $maxInFlight = 250;
    $startedAt = hrtime(true);
    $arrivalIndex = 0;
    $lastProgressAt = 0.0;

    do {
        $now = hrtime(true);
        $elapsedSeconds = ($now - $startedAt) / 1_000_000_000;
        $rate = rateAt($elapsedSeconds, $stages);

        if ($elapsedSeconds - $lastProgressAt >= 30.0 && $elapsedSeconds < $profileDuration) {
            $lastProgressAt = $elapsedSeconds;
            fwrite(STDERR, sprintf(
                "[loadtest] %.0f/%.0f sec, target %.1f req/s, completed %d, failures %d, inflight %d\n",
                $elapsedSeconds,
                $profileDuration,
                $rate,
                count($latencies),
                $failures,
                count($active)
            ));
        }

        if ($arrivalIndex < count($arrivalSchedule)) {
            [$arrivalAtSeconds, $stageIndex, $scheduledRate] = $arrivalSchedule[$arrivalIndex];
            $arrivalDueAt = $startedAt + (int) ($arrivalAtSeconds * 1_000_000_000);

            if ($now >= $arrivalDueAt) {
                $arrivalIndex++;
                $intervalNs = (int) (1_000_000_000 / $scheduledRate);
                if (($now - $arrivalDueAt) > $intervalNs) {
                    $schedulerSkipped++;
                    $stageStats[$stageIndex]['skipped_by_scheduler']++;
                } elseif (count($active) >= $maxInFlight) {
                    $droppedAtConcurrencyLimit++;
                    $stageStats[$stageIndex]['dropped_at_concurrency_limit']++;
                } else {
                    $sequence++;
                    $token = $sessions[($sequence - 1) % count($sessions)];
                    $clientMessageId = sprintf('00000000-0000-4000-8000-%012d', $sequence % 1_000_000_000_000);
                    $requestBody = json_encode([
                        'content' => sprintf('Synthetic Webchat load message %d', $sequence),
                        'client_message_id' => $clientMessageId,
                    ], JSON_THROW_ON_ERROR);
                    $handle = curl_init($baseUrl.'/api/v1/public/webchat/messages');
                    curl_setopt_array($handle, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $requestBody,
                        CURLOPT_HTTPHEADER => [
                            'Accept: application/json',
                            'Content-Type: application/json',
                            'Origin: '.$origin,
                            'Authorization: Bearer '.$token,
                            'Connection: keep-alive',
                        ],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CONNECTTIMEOUT_MS => 3000,
                        CURLOPT_TIMEOUT_MS => 10000,
                        CURLOPT_NOSIGNAL => true,
                    ]);
                    curl_multi_add_handle($multi, $handle);
                    $active[spl_object_id($handle)] = [
                        'handle' => $handle,
                        'sequence' => $sequence,
                        'stage_index' => $stageIndex,
                    ];
                    $stageStats[$stageIndex]['requests_launched']++;
                }
            }
        }

        do {
            $multiStatus = curl_multi_exec($multi, $running);
        } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($multi)) {
            $handle = $info['handle'];
            $key = spl_object_id($handle);
            $body = curl_multi_getcontent($handle);
            $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $durationMs = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
            $latencies[] = $durationMs;
            $stageIndex = $active[$key]['stage_index'];
            $stageStats[$stageIndex]['latencies_ms'][] = $durationMs;
            $statusCounts[(string) $httpStatus] = ($statusCounts[(string) $httpStatus] ?? 0) + 1;
            $decoded = is_string($body) ? json_decode($body, true) : null;
            $success = $info['result'] === CURLE_OK
                && $httpStatus === 201
                && is_array($decoded)
                && containsBotReply($decoded['messages'] ?? []);

            if (! $success) {
                $failures++;
                $stageStats[$stageIndex]['request_failures']++;
                if (count($failureSamples) < 10) {
                    $failureSamples[] = [
                        'request' => $active[$key]['sequence'] ?? null,
                        'http_status' => $httpStatus,
                        'curl_error' => curl_error($handle),
                        'body_excerpt' => substr((string) $body, 0, 200),
                    ];
                }
            } else {
                $stageStats[$stageIndex]['requests_successful']++;
            }

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            unset($active[$key]);
        }

        if ($running > 0) {
            $selected = curl_multi_select($multi, 0.01);
            if ($selected === -1) {
                usleep(1000);
            }
        } elseif ($arrivalIndex < count($arrivalSchedule)) {
            usleep(1000);
        }
    } while ($elapsedSeconds < $profileDuration || $active !== [] || $arrivalIndex < count($arrivalSchedule));

    $wallDuration = (hrtime(true) - $startedAt) / 1_000_000_000;
    curl_multi_close($multi);
    $requestCount = count($latencies);
    $errorRate = $requestCount > 0 ? $failures / $requestCount : 1.0;
    $p95 = percentile($latencies, 95);
    $p99 = percentile($latencies, 99);
    $expectedArrivals = count($arrivalSchedule);
    foreach ($stageStats as &$stage) {
        $values = $stage['latencies_ms'];
        $stage['target_average_requests_per_second'] = round(
            ($stage['target_start_requests_per_second'] + $stage['target_end_requests_per_second']) / 2,
            2
        );
        $stage['achieved_launches_per_second'] = round(
            $stage['requests_launched'] / $stage['duration_seconds'],
            2
        );
        $stage['achieved_successes_per_second'] = round(
            $stage['requests_successful'] / $stage['duration_seconds'],
            2
        );
        $stage['latency_ms'] = [
            'p50' => percentile($values, 50),
            'p95' => percentile($values, 95),
            'p99' => percentile($values, 99),
            'max' => $values === [] ? null : round(max($values), 2),
        ];
        unset($stage['latencies_ms']);
    }
    unset($stage);
    $totalDropped = $droppedAtConcurrencyLimit + $schedulerSkipped;
    $schedulerSkipRate = $expectedArrivals > 0 ? $schedulerSkipped / $expectedArrivals : 1.0;
    $thresholds = [
        'error_rate_below_1_percent' => $errorRate < 0.01,
        'p95_below_1000_ms' => $p95 !== null && $p95 < 1000,
        'p99_below_2500_ms' => $p99 !== null && $p99 < 2500,
        'no_concurrency_drops' => $droppedAtConcurrencyLimit === 0,
        'scheduler_skips_below_0_1_percent' => $expectedArrivals > 0 && $schedulerSkipRate < 0.001,
    ];
    $report = [
        'mode' => 'load',
        'profile' => $profile,
        'passed' => ! in_array(false, $thresholds, true),
        'requested_profile_seconds' => $profileDuration,
        'wall_duration_seconds_including_drain' => round($wallDuration, 3),
        'session_setup_seconds' => round($setupDuration, 3),
        'sessions_created' => count($sessions),
        'target_peak_requests_per_second' => max(array_merge(
            array_column($stages, 1),
            array_column($stages, 2)
        )),
        'target_arrivals_expected' => (int) round($expectedArrivals),
        'target_arrivals_accounted_for' => $requestCount + $droppedAtConcurrencyLimit + $schedulerSkipped,
        'requests_completed' => $requestCount,
        'requests_successful' => $requestCount - $failures,
        'request_failures' => $failures,
        'error_rate' => round($errorRate, 6),
        'dropped_requests_at_max_concurrency' => $droppedAtConcurrencyLimit,
        'request_iterations_skipped_by_scheduler' => $schedulerSkipped,
        'scheduler_skip_rate' => round($schedulerSkipRate, 6),
        'dropped_requests_total' => $totalDropped,
        'actual_throughput_requests_per_second' => round($wallDuration > 0 ? $requestCount / $wallDuration : 0, 2),
        'latency_ms' => [
            'p50' => percentile($latencies, 50),
            'p95' => $p95,
            'p99' => $p99,
            'max' => $latencies === [] ? null : round(max($latencies), 2),
        ],
        'http_status_counts' => $statusCounts,
        'stages' => $stageStats,
        'thresholds' => $thresholds,
        'failure_samples' => $failureSamples,
        'note' => 'Generator and Webchat backend share the isolated backend container and its 2 CPU limit; interpret throughput as a conservative local end-to-end result, not an isolated production capacity guarantee.',
    ];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($report['passed'] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
