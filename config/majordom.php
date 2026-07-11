<?php

return [
    'token' => env('MAJORDOM_TOKEN'),
    'metallama' => [
        'base_url' => env('METALLAMA_BASE_URL', 'http://127.0.0.1:8010'),
        'token' => env('METALLAMA_TOKEN'),
        'timeout' => (int) env('METALLAMA_TIMEOUT', 10),
    ],
];
