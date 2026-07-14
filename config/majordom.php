<?php

return [
    'token' => env('MAJORDOM_TOKEN'),
    'memory_root' => env('MAJORDOM_MEMORY_ROOT'),
    'worktrees_root' => env('MAJORDOM_WORKTREES_ROOT'), // null => $HOME/.majordom/worktrees
    'metallama' => [
        'base_url' => env('METALLAMA_BASE_URL', 'http://127.0.0.1:8010'),
        'token' => env('METALLAMA_TOKEN'),
        'timeout' => (int) env('METALLAMA_TIMEOUT', 10),
        'start_timeout' => (int) env('METALLAMA_START_TIMEOUT', 300),
        'stop_timeout' => (int) env('METALLAMA_STOP_TIMEOUT', 60),
        'poll_interval_ms' => (int) env('METALLAMA_POLL_INTERVAL_MS', 2000),
    ],

    // The Builder's model: 'model' is the metallama managed-server id the
    // resource coordinator ensures; 'gateway_model' is the routable name the
    // harness passes to the OpenAI-compatible gateway (differs when no alias
    // is configured in metallama — then it's the full GGUF path).
    'builder' => [
        'model' => env('MAJORDOM_BUILDER_MODEL'),
        'gateway_model' => env('MAJORDOM_BUILDER_GATEWAY_MODEL'),
    ],

    'workflow' => [
        // Bounded revise loop: test failures / review changes re-arm the
        // build this many times before the execution parks for the human.
        'max_revisions' => (int) env('MAJORDOM_MAX_REVISIONS', 3),
        // Overnight executions carry a frontier-spend ceiling (SPEC §8).
        'overnight_spend_cap_usd' => (float) env('MAJORDOM_OVERNIGHT_SPEND_CAP', 1.00),
    ],

    // Autonomy profiles are DATA (SPEC §8): per gate, 'block' pings the
    // human, 'auto' proceeds-and-collects. Consensus questions always
    // block (never listed here); commit NEVER auto-runs (not a gate the
    // engine can open — the CommitSuggestion just waits).
    'profiles' => [
        'attended' => ['review' => 'block'],
        'overnight' => ['review' => 'auto'],
    ],

    'harness' => [
        'aider_bin' => env('MAJORDOM_AIDER_BIN', 'aider'),
        'timeout' => (int) env('MAJORDOM_HARNESS_TIMEOUT', 1800), // seconds
    ],

    // Committer identity for the human-gated promotion commit. The app process
    // may run without the user's ~/.gitconfig visible (snap/systemd sandbox a
    // different $HOME), so git can't resolve author identity on its own. When
    // set, these are passed explicitly to the commit; otherwise CommitService
    // falls back to the repo's own resolved git identity.
    'git' => [
        'author_name' => env('MAJORDOM_GIT_AUTHOR_NAME'),
        'author_email' => env('MAJORDOM_GIT_AUTHOR_EMAIL'),
    ],

    'providers' => [
        'openrouter' => [
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key' => env('OPENROUTER_API_KEY'),
            'timeout' => (int) env('PROVIDER_TIMEOUT', 120),
        ],
    ],

    // Frontier role bindings (Settings → Actors will make these DB-backed
    // with per-project overrides later; env is the built-in default layer).
    'architect' => [
        'model' => env('MAJORDOM_ARCHITECT_MODEL', 'deepseek/deepseek-v4-flash'),
        'max_tokens' => (int) env('MAJORDOM_ARCHITECT_MAX_TOKENS', 4000),
        'plan_max_tokens' => (int) env('MAJORDOM_ARCHITECT_PLAN_MAX_TOKENS', 8000),
        'temperature' => (float) env('MAJORDOM_ARCHITECT_TEMPERATURE', 0.3),
    ],

    // Reviewer defaults to the Architect's model until bound separately in
    // Settings → Actors (GLM 5.2 is the standing candidate).
    'reviewer' => [
        'model' => env('MAJORDOM_REVIEWER_MODEL') ?: env('MAJORDOM_ARCHITECT_MODEL', 'deepseek/deepseek-v4-flash'),
        'max_tokens' => (int) env('MAJORDOM_REVIEWER_MAX_TOKENS', 3000),
        'temperature' => (float) env('MAJORDOM_REVIEWER_TEMPERATURE', 0.2),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'poll_timeout' => (int) env('TELEGRAM_POLL_TIMEOUT', 30),
        // true => messages deliver without a push notification (dev/test)
        'silent' => (bool) env('TELEGRAM_SILENT', false),
    ],
];
