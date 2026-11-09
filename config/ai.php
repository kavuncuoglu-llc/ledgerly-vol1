<?php

return [

    'provider' => env('AI_PROVIDER', 'ollama'),

    'base_url' => env('AI_BASE_URL', 'http://localhost:11434/v1'),

    'api_key' => env('AI_API_KEY', 'ollama'),

    'model' => env('AI_MODEL', 'llama3.1:8b'),

    'vision_model' => env('AI_VISION_MODEL', 'llama3.2-vision:11b'),

    'embed_model' => env('AI_EMBED_MODEL', 'nomic-embed-text'),

    'embed_dimensions' => (int) env('AI_EMBED_DIMENSIONS', 768),

    'connect_timeout' => env('AI_CONNECT_TIMEOUT', 2),

    'timeout' => env('AI_TIMEOUT', 120),

    'rate_limit' => (int) env('AI_RATE_LIMIT', 30),

    'token_counter' => env('AI_TOKEN_COUNTER', 'ratio'),

    'profiles' => [
        'deterministic' => [
            'temperature' => 0,
            'top_p' => 1.0,
            'seed' => 42,
        ],
        'grounded' => [
            'temperature' => 0.2,
            'top_p' => 0.9,
        ],
        'assistant' => [
            'temperature' => 0.7,
            'top_p' => 0.9,
        ],
        'creative' => [
            'temperature' => 1.0,
            'top_p' => 0.95,
        ],
    ],

    'chunking' => [
        'min_tokens' => 200,
        'max_tokens' => 400,
    ],

    'budget' => [
        'window' => (int) env('AI_CONTEXT_WINDOW', 8192),
        'answer' => 400,
        'system' => 450,
        'margin_pct' => 0.05,
        'context' => 3500,
    ],

    'mcp_servers' => [],

    'tool_allow_lists' => [],

    'mcp_stdio_user_email' => env('MCP_STDIO_USER_EMAIL'),

    'rates' => [
        'ollama' => [
            'llama3.1:8b' => [
                'in' => 0,
                'out' => 0,
            ],
        ],
    ],

];
