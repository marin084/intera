<?php

return [
    'service_targets' => [
        'queue_wait_seconds' => max(60, min(86400, (int) env('TEAM_DEFAULT_QUEUE_WAIT_SECONDS', 120))),
        'first_response_seconds' => max(60, min(86400, (int) env('TEAM_DEFAULT_FIRST_RESPONSE_SECONDS', 300))),
    ],
];
