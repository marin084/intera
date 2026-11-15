<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Steps Per Flow Execution Run
    |--------------------------------------------------------------------------
    |
    | Defines the maximum number of automatic steps the runtime engine can execute
    | in a single run before suspecting an infinite loop and halting execution.
    |
    */
    'max_steps_per_run' => (int) env('FLOW_MAX_STEPS_PER_RUN', 100),

    /*
    |--------------------------------------------------------------------------
    | Maximum Node Visits Per Single Turn (Loop Protection)
    |--------------------------------------------------------------------------
    |
    | Maximum times any single node can be visited within the same automatic turn
    | without human input. Visiting the same node repeatedly indicates an automatic loop.
    |
    */
    'max_node_visits_per_turn' => (int) env('FLOW_MAX_NODE_VISITS_PER_TURN', 5),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Node Security & Limits
    |--------------------------------------------------------------------------
    |
    | Configuration for outgoing HTTP calls executed by HttpRequestNodeHandler.
    | Includes SSRF protections, response size limits, and configurable allowed hosts.
    |
    */
    'http' => [
        'default_timeout' => (int) env('FLOW_HTTP_DEFAULT_TIMEOUT', 5),
        'max_timeout' => (int) env('FLOW_HTTP_MAX_TIMEOUT', 15),
        'max_response_bytes' => (int) env('FLOW_HTTP_MAX_RESPONSE_BYTES', 2097152), // 2 MB
        'allowed_hosts' => array_filter(explode(',', (string) env('FLOW_HTTP_ALLOWED_HOSTS', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Flow Version Graph Caching
    |--------------------------------------------------------------------------
    |
    | Since published flow versions are strictly immutable, caching compiled graphs
    | avoids repeated relational queries (flow_nodes, flow_edges) on high-throughput runs.
    |
    */
    'cache' => [
        'enabled' => (bool) env('FLOW_GRAPH_CACHE_ENABLED', true),
        'ttl_seconds' => (int) env('FLOW_GRAPH_CACHE_TTL', 3600),
    ],
];
