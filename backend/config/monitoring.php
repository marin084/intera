<?php

return [
    'discord' => [
        'timeout_seconds' => (int) env('DISCORD_ALERT_TIMEOUT_SECONDS', 5),
    ],

    'meta_token' => [
        'timeout_seconds' => (int) env('META_TOKEN_CHECK_TIMEOUT_SECONDS', 10),
        'timezone' => env('META_TOKEN_ALERT_TIMEZONE', 'America/Costa_Rica'),
        'warning_hours' => [24, 1],
    ],
];
