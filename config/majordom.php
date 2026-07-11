<?php

return [
    'token' => env('MAJORDOM_TOKEN'),
    'metallama' => [
        'base_url' => env('METALLAMA_BASE_URL', 'http://127.0.0.1:8010'),
        'token' => env('METALLAMA_TOKEN'),
        'timeout' => (int) env('METALLAMA_TIMEOUT', 10),
    ],

    // The Builder's model: 'model' is the metallama managed-server id the
    // resource coordinator ensures; 'gateway_model' is the routable name the
    // harness passes to the OpenAI-compatible gateway (differs when no alias
    // is configured in metallama — then it's the full GGUF path).
    'builder' => [
        'model' => env('MAJORDOM_BUILDER_MODEL'),
        'gateway_model' => env('MAJORDOM_BUILDER_GATEWAY_MODEL'),
    ],
];
