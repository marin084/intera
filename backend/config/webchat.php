<?php

return [
    'session_ttl_days' => (int) env('WEBCHAT_SESSION_TTL_DAYS', 30),
    'max_message_length' => (int) env('WEBCHAT_MAX_MESSAGE_LENGTH', 4000),
    'embed_token_ttl_seconds' => (int) env('WEBCHAT_EMBED_TOKEN_TTL_SECONDS', 900),
    'rate_limit_embed' => (int) env('WEBCHAT_RATE_LIMIT_EMBED', 120),
    'rate_limit_session' => (int) env('WEBCHAT_RATE_LIMIT_SESSION', 120),
    'rate_limit_messages' => (int) env('WEBCHAT_RATE_LIMIT_MESSAGES', 120),
];
