<?php

$ollamaUrl = env('OLLAMA_URL', 'http://localhost:11434/v1');

$localRole = match (env('AI_LOCAL_PROVIDER', 'ollama')) {
    'llama' => 'local-llama',
    'vllm' => 'vllm',
    'triton' => 'triton',
    default => 'local',
};

$adapterFor = fn (string $variable): ?string => $localRole === 'vllm'
    ? (env($variable) ?: null)
    : null;

return [

    'default' => env('AI_DEFAULT_PROVIDER', 'local'),

    'providers' => [
        'local' => [
            'driver' => env('AI_DRIVER', 'openai-compatible'),
            'base_url' => env('AI_BASE_URL', $ollamaUrl),
            'api_key' => env('AI_API_KEY', 'ollama'),
            'model' => env('AI_MODEL', 'llama3.1:8b'),
            'ollama' => env('AI_OLLAMA') === null
                ? null
                : filter_var(env('AI_OLLAMA'), FILTER_VALIDATE_BOOLEAN),
            'llama' => env('AI_LLAMA') === null
                ? null
                : filter_var(env('AI_LLAMA'), FILTER_VALIDATE_BOOLEAN),
            'vllm' => env('AI_VLLM') === null
                ? null
                : filter_var(env('AI_VLLM'), FILTER_VALIDATE_BOOLEAN),
            'triton' => env('AI_TRITON') === null
                ? null
                : filter_var(env('AI_TRITON'), FILTER_VALIDATE_BOOLEAN),
            'license' => env('AI_LICENSE', 'Llama 3.1 Community License'),
            'license_reviewed' => env('AI_LICENSE_REVIEWED') ?: null,
            'gpu_hour_cost' => is_numeric(env('AI_GPU_HOUR_COST'))
                ? (float) env('AI_GPU_HOUR_COST')
                : null,
            'cache_breakpoints' => env('AI_CACHE_BREAKPOINTS') === null
                ? null
                : filter_var(env('AI_CACHE_BREAKPOINTS'), FILTER_VALIDATE_BOOLEAN),
        ],
        'local-embed' => [
            'driver' => 'openai-compatible',
            'base_url' => env('AI_BASE_URL', $ollamaUrl),
            'api_key' => env('AI_API_KEY', 'ollama'),
            'model' => env('AI_EMBED_MODEL', 'nomic-embed-text'),
            'license' => env('AI_EMBED_LICENSE', 'Apache 2.0'),
            'license_reviewed' => env('AI_LICENSE_REVIEWED') ?: null,
            'dimensions' => (int) env('AI_EMBED_DIMENSIONS', 768),
            'prefixes' => [
                'query' => 'search_query: ',
                'document' => 'search_document: ',
            ],
        ],
        'vision' => [
            'driver' => 'openai-compatible',
            'base_url' => env('AI_BASE_URL', $ollamaUrl),
            'api_key' => env('AI_API_KEY', 'ollama'),
            'model' => env('AI_VISION_MODEL', 'llama3.2-vision:11b'),
            'license' => env('AI_VISION_LICENSE', 'Llama 3.2 Community License'),
            'license_reviewed' => env('AI_LICENSE_REVIEWED') ?: null,
            'vision' => true,
        ],
        'mid' => [
            'driver' => env('MID_DRIVER', 'openai-compatible'),
            'base_url' => env('MID_URL') ?: null,
            'api_key' => env('MID_KEY') ?: null,
            'model' => env('MID_MODEL') ?: null,
            'timeout' => (int) env('MID_TIMEOUT', 120),
            'license' => env('MID_LICENSE') ?: null,
            'license_reviewed' => env('AI_LICENSE_REVIEWED') ?: null,
            'gpu_hour_cost' => is_numeric(env('MID_GPU_HOUR_COST'))
                ? (float) env('MID_GPU_HOUR_COST')
                : null,
        ],
        'local-70b' => [
            'driver' => 'openai-compatible',
            'base_url' => env('LOCAL_70B_URL', env('AI_BASE_URL', $ollamaUrl)),
            'api_key' => env('AI_API_KEY', 'ollama'),
            'model' => env('LOCAL_70B_MODEL', 'ledgerly-support-70b'),
            'timeout' => (int) env('LOCAL_70B_TIMEOUT', 300),
            'license' => env('LOCAL_70B_LICENSE', 'Llama 3.1 Community License'),
            'license_reviewed' => env('AI_LICENSE_REVIEWED') ?: null,
            'gpu_hour_cost' => is_numeric(env('LOCAL_70B_GPU_HOUR_COST'))
                ? (float) env('LOCAL_70B_GPU_HOUR_COST')
                : null,
        ],
        'local-llama' => [
            'driver' => 'llama-server',
            'base_url' => env('LLAMA_URL', 'http://127.0.0.1:8080/v1'),
            'api_key' => env('LLAMA_API_KEY', 'llama'),
            'model' => env('LLAMA_MODEL_ALIAS', 'ledgerly-support'),
            'timeout' => (int) env('LLAMA_TIMEOUT', 120),
            'license' => env('LLAMA_LICENSE', 'Llama 3.1 Community License'),
            'license_reviewed' => env('AI_LICENSE_REVIEWED') ?: null,
            'gpu_hour_cost' => is_numeric(env('LLAMA_GPU_HOUR_COST'))
                ? (float) env('LLAMA_GPU_HOUR_COST')
                : null,
        ],
        'vllm' => [
            'driver' => 'vllm',
            'base_url' => env('VLLM_URL', 'http://127.0.0.1:8001/v1'),
            'api_key' => env('VLLM_API_KEY', 'vllm'),
            'model' => env('VLLM_MODEL_ALIAS', 'ledgerly-support'),
            'timeout' => (int) env('VLLM_TIMEOUT', 60),
            'license' => env('VLLM_LICENSE', 'Llama 3.1 Community License'),
            'license_reviewed' => env('AI_LICENSE_REVIEWED') ?: null,
            'gpu_hour_cost' => is_numeric(env('VLLM_GPU_HOUR_COST'))
                ? (float) env('VLLM_GPU_HOUR_COST')
                : null,
            'option_renames' => [
                'repeat_penalty' => 'repetition_penalty',
            ],
        ],
        'triton' => [
            'driver' => 'triton',
            'base_url' => env('TRITON_URL', 'http://127.0.0.1:9000/v1'),
            'kserve_url' => env('TRITON_KSERVE_URL') ?: null,
            'api_key' => env('TRITON_API_KEY', 'triton'),
            'auth_header' => env('TRITON_AUTH_HEADER', 'x-api-key'),
            'model' => env('TRITON_MODEL_ALIAS', 'chat'),
            'timeout' => (int) env('TRITON_TIMEOUT', 60),
            'default_max_tokens' => (int) env('TRITON_DEFAULT_MAX_TOKENS', 512),
            'capabilities' => [
                'tools',
                'seed',
            ],
            'unsupported_options' => [
                'min_p',
                'top_k',
                'repeat_penalty',
                'num_ctx',
            ],
            'license' => env('TRITON_LICENSE', 'Llama 3.1 Community License'),
            'license_reviewed' => env('AI_LICENSE_REVIEWED') ?: null,
            'gpu_hour_cost' => is_numeric(env('TRITON_GPU_HOUR_COST'))
                ? (float) env('TRITON_GPU_HOUR_COST')
                : null,
        ],
        'frontier' => [
            'driver' => env('FRONTIER_DRIVER', 'anthropic'),
            'base_url' => env('FRONTIER_URL') ?: null,
            'api_key' => env('FRONTIER_KEY') ?: null,
            'model' => env('FRONTIER_MODEL') ?: null,
            'timeout' => (int) env('FRONTIER_TIMEOUT', 60),
            'max_tokens' => (int) env('FRONTIER_MAX_TOKENS', 1024),
            'vision' => true,
            'cache_breakpoints' => env('FRONTIER_CACHE_BREAKPOINTS') === null
                ? null
                : filter_var(env('FRONTIER_CACHE_BREAKPOINTS'), FILTER_VALIDATE_BOOLEAN),
        ],
        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_DEFAULT_REGION') ?: null,
            'model' => env('BEDROCK_MODEL') ?: null,
            'access_key' => env('AWS_ACCESS_KEY_ID') ?: null,
            'secret_key' => env('AWS_SECRET_ACCESS_KEY') ?: null,
            'session_token' => env('AWS_SESSION_TOKEN') ?: null,
            'timeout' => (int) env('BEDROCK_TIMEOUT', 60),
            'max_tokens' => (int) env('BEDROCK_MAX_TOKENS', 1024),
            'vision' => true,
        ],
        'rerank' => [
            'driver' => 'sidecar',
            'model' => 'bge-reranker-base',
        ],
        'rerank-lexical' => [
            'driver' => 'lexical',
        ],
        'fake' => [
            'driver' => 'fake',
        ],
    ],

    'ollama' => [
        'native' => (bool) env('AI_OLLAMA_NATIVE', false),
        'keep_alive' => env('AI_OLLAMA_KEEP_ALIVE', '30m'),
        'num_ctx' => env('AI_NUM_CTX') ?: null,
        'num_parallel' => env('OLLAMA_NUM_PARALLEL') ?: null,
        'providers' => array_filter(explode(',', (string) env('AI_OLLAMA_CHECK_PROVIDERS', 'local,local-embed,vision'))),
        'probe_timeout' => (int) env('AI_OLLAMA_PROBE_TIMEOUT', 120),
    ],

    'llama' => [
        'provider' => env('AI_LLAMA_PROVIDER', 'local-llama'),
        'gpu_layers' => env('LLAMA_NGL') ?: null,
        'model_layers' => is_numeric(env('LLAMA_MODEL_LAYERS'))
            ? (int) env('LLAMA_MODEL_LAYERS')
            : null,
        'backend' => env('LLAMA_BACKEND', 'gpu'),
        'probe_timeout' => (int) env('AI_LLAMA_PROBE_TIMEOUT', 60),
    ],

    'vllm' => [
        'provider' => env('AI_VLLM_PROVIDER', 'vllm'),
        'repository' => env('VLLM_MODEL') ?: null,
        'revision' => env('VLLM_REVISION') ?: null,
        'quantization' => env('VLLM_QUANTIZATION') ?: null,
        'tool_parser' => env('VLLM_TOOL_PARSER') ?: null,
        'max_num_seqs' => is_numeric(env('VLLM_MAX_NUM_SEQS'))
            ? (int) env('VLLM_MAX_NUM_SEQS')
            : null,
        'probe_timeout' => (int) env('AI_VLLM_PROBE_TIMEOUT', 60),
    ],

    'triton' => [
        'provider' => env('AI_TRITON_PROVIDER', 'triton'),
        'image_tag' => env('TRITON_TAG') ?: null,
        'backend' => env('TRITON_BACKEND') ?: null,
        'tokenizer' => env('TRITON_TOKENIZER') ?: null,
        'tool_parser' => env('TRITON_TOOL_PARSER') ?: null,
        'repository' => env('TRITON_MODEL_REPOSITORY') ?: null,
        'models' => array_filter(explode(',', (string) env('TRITON_MODELS', 'chat'))),
        'probe_timeout' => (int) env('AI_TRITON_PROBE_TIMEOUT', 60),
    ],

    'failover' => array_filter([
        $localRole => env('AI_FALLBACK_LOCAL') ?: null,
    ]),

    'breaker' => [
        'enabled' => (bool) env('AI_BREAKER', true),
        'threshold' => (int) env('AI_BREAKER_THRESHOLD', 3),
        'open_seconds' => (int) env('AI_BREAKER_OPEN_SECONDS', 60),
        'memory_seconds' => (int) env('AI_BREAKER_MEMORY_SECONDS', 600),
        'store' => env('AI_BREAKER_STORE') ?: null,
    ],

    'health' => [
        'warm_ms' => (int) env('AI_HEALTH_WARM_MS', 2000),
        'probe_timeout' => (int) env('AI_HEALTH_PROBE_TIMEOUT', 120),
    ],

    'compose' => [
        'file' => env('AI_COMPOSE_FILE') ?: base_path('docker-compose.yml'),
        'aliases' => [
            'ollama' => 'linux-gpu',
            'llama' => 'llama-gpu',
        ],
        'profiles' => [
            'linux-gpu' => [
                'provider' => 'local',
                'nvidia' => false,
                'port' => (int) env('OLLAMA_PORT', 11434),
            ],
            'llama-gpu' => [
                'provider' => 'local-llama',
                'nvidia' => true,
                'port' => (int) env('LLAMA_PORT', 8080),
            ],
            'llama-cpu' => [
                'provider' => 'local-llama',
                'nvidia' => false,
                'port' => (int) env('LLAMA_PORT', 8080),
            ],
            'vllm' => [
                'provider' => 'vllm',
                'nvidia' => true,
                'port' => (int) env('VLLM_PORT', 8001),
            ],
            'triton' => [
                'provider' => 'triton',
                'nvidia' => true,
                'port' => (int) env('TRITON_PORT', 9000),
            ],
            'monitoring' => [
                'provider' => null,
                'nvidia' => false,
                'port' => (int) env('GRAFANA_PORT', 3000),
            ],
        ],
    ],

    'load' => [
        'directory' => env('AI_LOAD_DIRECTORY') ?: storage_path('benchmarks'),
        'shape_file' => env('AI_LOAD_SHAPE_FILE', 'storage/benchmarks/traffic-shape.jsonl'),
        'concurrency' => env('AI_LOAD_CONCURRENCY', '1,4,8,16,32,64'),
        'ttft_p99_ms' => (int) env('AI_LOAD_TTFT_P99_MS', 1000),
        'vllm_binary' => env('AI_LOAD_VLLM_BIN', 'vllm'),
        'timeout' => (int) env('AI_LOAD_TIMEOUT', 900),
    ],

    'tiers' => [
        'small' => $localRole,
        'mid' => env('AI_MID_PROVIDER', 'mid'),
        'frontier' => 'frontier',
    ],

    'features' => [
        'ticket.classify' => [
            'provider' => $localRole,
            'model' => $adapterFor('VLLM_CLASSIFY_ADAPTER'),
            'profile' => 'deterministic',
        ],
        'ticket.extract' => [
            'provider' => $localRole,
            'profile' => 'deterministic',
        ],
        'support.reply' => [
            'provider' => $localRole,
            'model' => $adapterFor('VLLM_SUPPORT_ADAPTER'),
            'profile' => 'assistant',
            'escalate_to' => 'frontier',
        ],
        'invoice.extract' => [
            'provider' => 'vision',
            'profile' => 'deterministic',
        ],
        'conversation.summarize' => [
            'provider' => $localRole,
            'profile' => 'deterministic',
        ],
    ],

    'embedding' => [
        'provider' => 'local-embed',
        'batch_size' => (int) env('AI_EMBED_BATCH', 48),
    ],

    'reranking' => [
        'provider' => 'rerank',
    ],

    'debug' => [
        'record_requests' => (bool) env('AI_RECORD_REQUESTS', false),
        'record_requests_days' => (int) env('AI_RECORD_REQUESTS_DAYS', 7),
        'features' => array_filter(explode(',', (string) env('AI_DEBUG_FEATURES', ''))),
        'conversations' => array_map('intval', array_filter(explode(',', (string) env('AI_DEBUG_CONVERSATIONS', '')))),
    ],

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
        'strategy' => env('AI_CHUNK_STRATEGY', 'parent-child'),
        'child_max_tokens' => 200,
    ],

    'budget' => [
        'window' => (int) env('AI_CONTEXT_WINDOW', 8192),
        'answer' => 400,
        'system' => 450,
        'margin_pct' => 0.05,
        'context' => 3500,
    ],

    'slot_budgets' => [
        'memories' => 150,
        'summary' => 400,
        'context' => 3500,
    ],

    'prompt_cache' => [
        'prefix_memory_minutes' => 60,
    ],

    'semantic_cache' => [
        'enabled' => (bool) env('AI_SEMANTIC_CACHE', true),
        'thresholds' => [
            'howto' => 0.92,
        ],
        'ttl_days' => 30,
        'require_grounding' => (bool) env('AI_SEMANTIC_CACHE_REQUIRE_GROUNDING', true),
    ],

    'retrieval' => [
        'mode' => env('AI_RETRIEVAL_MODE', 'hybrid'),
        'similarity_floor' => (float) env('AI_RETRIEVAL_FLOOR', 0.70),
        'k' => 5,
        'rrf_constant' => 60,
        'candidate_pool' => 20,
        'reranker' => env('AI_RERANKER', 'sidecar'),
        'rerank_candidates' => (int) env('AI_RETRIEVAL_RERANK_CANDIDATES', 20),
        'rewrite' => env('AI_RETRIEVAL_REWRITE', 'rewrite'),
        'rewrite_model' => env('AI_RETRIEVAL_REWRITE_MODEL') ?: null,
        'context_model' => env('AI_RETRIEVAL_CONTEXT_MODEL') ?: null,
        'golden_set' => 'storage/golden/retrieval.jsonl',
    ],

    'sidecar' => [
        'url' => env('AI_SIDECAR_URL', 'http://localhost:8008'),
        'timeout' => (int) env('AI_SIDECAR_TIMEOUT', 5),
        'parse_timeout' => (int) env('AI_SIDECAR_PARSE_TIMEOUT', 180),
        'max_upload_mb' => 25,
    ],

    'ingestion' => [
        'boilerplate_patterns' => [
            '/^\s*(Was this (article|page) helpful\?|Did this answer your question\?).*$/mi',
            '/^\s*(Back to (the )?help center|Skip to (main )?content)\s*$/mi',
            '/^\s*(Last updated|Article last reviewed):.*$/mi',
            '/^\s*(This article is provided for information only|Ledgerly may change this policy)[^\n]*$/mi',
        ],
    ],

    'conversation' => [
        'keep_recent_exchanges' => (int) env('AI_CONVERSATION_KEEP_RECENT', 4),
        'compact_after_exchanges' => (int) env('AI_CONVERSATION_COMPACT_AFTER', 2),
        'summary_trigger_tokens' => env('AI_SUMMARY_TRIGGER_TOKENS') ?: null,
        'summary_max_tokens' => 400,
        'summary_input_tokens' => 4000,
        'summary_context_window' => env('AI_SUMMARY_CONTEXT_WINDOW') ?: null,
        'message_overhead_tokens' => 4,
    ],

    'memory' => [
        'max_facts' => 12,
        'min_confidence' => 0.7,
        'expire_after_days' => 365,
    ],

    'retention' => [
        'closed_conversation_days' => (int) env('AI_RETENTION_CLOSED_CONVERSATION_DAYS', 90),
    ],

    'encoding' => [
        'default' => env('AI_ENCODING_DEFAULT', 'toon'),
        'features' => [
            'list_invoices' => env('AI_ENCODING_LIST_INVOICES') ?: null,
        ],
    ],

    'routing' => [
        'enabled' => (bool) env('AI_ROUTING', true),
        'samples' => (int) env('AI_ROUTING_SAMPLES', 3),
        'agreement_threshold' => (float) env('AI_ROUTING_AGREEMENT', 0.67),
        'mid_below_agreement' => (float) env('AI_ROUTING_MID_BELOW_AGREEMENT', 0.67),
        'frontier_after_rounds' => (int) env('AI_ROUTING_FRONTIER_AFTER_ROUNDS', 2),
    ],

    'agents' => [
        'support' => [
            'max_rounds' => 5,
            'max_tokens' => 20_000,
            'max_seconds' => 60,
            'max_writes' => 1,
        ],
        'triage' => [
            'max_rounds' => 8,
            'max_tokens' => 30_000,
            'max_seconds' => 120,
            'max_writes' => 2,
        ],
        'reminder' => [
            'max_rounds' => 15,
            'max_tokens' => 80_000,
            'max_seconds' => 300,
            'max_writes' => 10,
        ],
    ],

    'triage' => [
        'urgent_amount' => (float) env('AI_TRIAGE_URGENT_AMOUNT', 1000),
        'draft_max_tokens' => 300,
        'retrieval_k' => 3,
    ],

    'replies' => [
        'enabled' => (bool) env('AI_QUEUED_REPLIES', true),
        'queue' => 'ai',
        'batch_queue' => 'ai-batch',
        'timeout' => 180,
        'tries' => 3,
        'backoff' => [5, 15, 60],
        'unique_for' => 900,
        'flush_ms' => (int) env('AI_STREAM_FLUSH_MS', 50),
        'flush_tokens' => (int) env('AI_STREAM_FLUSH_TOKENS', 20),
        'buffer_store' => env('AI_REPLY_BUFFER_STORE') ?: null,
        'buffer_ttl_seconds' => 900,
        'buffer_done_ttl_seconds' => 60,
        'broadcast_pause_seconds' => 5,
        'sse_max_seconds' => 120,
        'sse_poll_ms' => 100,
        'paused_expiry_days' => (int) env('AI_PAUSED_EXPIRY_DAYS', 7),
        'queue_depth_alert' => (int) env('AI_QUEUE_DEPTH_ALERT', 20),
        'tool_labels' => [
            'lookup_invoice' => 'Looking up your invoice...',
            'list_invoices' => 'Checking your invoices...',
            'account_status' => 'Checking your account...',
            'open_ticket' => 'Opening a ticket...',
        ],
        'default_tool_label' => 'Working on it...',
    ],

    'horizon_viewers' => array_filter(explode(',', (string) env('AI_HORIZON_VIEWERS', ''))),

    'mcp_servers' => [],

    'tool_allow_lists' => [],

    'mcp_stdio_user_email' => env('MCP_STDIO_USER_EMAIL'),

    'rates' => [
        'local' => [
            'llama3.1:8b' => [
                'in' => 0,
                'out' => 0,
            ],
        ],
        'mid' => env('MID_MODEL')
            ? [
                env('MID_MODEL') => [
                    'in' => (float) env('MID_RATE_IN', 0),
                    'out' => (float) env('MID_RATE_OUT', 0),
                ],
            ]
            : [],
        'frontier' => env('FRONTIER_MODEL')
            ? [
                env('FRONTIER_MODEL') => [
                    'in' => (float) env('FRONTIER_RATE_IN', 0),
                    'out' => (float) env('FRONTIER_RATE_OUT', 0),
                ],
            ]
            : [],
        'bedrock' => env('BEDROCK_MODEL')
            ? [
                env('BEDROCK_MODEL') => [
                    'in' => (float) env('BEDROCK_RATE_IN', 0),
                    'out' => (float) env('BEDROCK_RATE_OUT', 0),
                ],
            ]
            : [],
    ],

    'hardware' => [
        'reachable_gib' => is_numeric(env('AI_REACHABLE_MEMORY_GIB'))
            ? (float) env('AI_REACHABLE_MEMORY_GIB')
            : null,
        'bandwidth_gbps' => is_numeric(env('AI_MEMORY_BANDWIDTH_GBPS'))
            ? (float) env('AI_MEMORY_BANDWIDTH_GBPS')
            : null,
        'macos_gpu_share' => (float) env('AI_MACOS_GPU_SHARE', 0.75),
        'headroom_pct' => (float) env('AI_HEADROOM_PCT', 0.12),
        'default_quantization' => 'q4_k_m',
        'quantizations' => [
            'f16' => 2.0,
            'q8_0' => 1.06,
            'q6_k' => 0.83,
            'q5_k_m' => 0.71,
            'q4_k_m' => 0.6,
            'q3_k_m' => 0.49,
        ],
        'cache_types' => [
            'f16' => 2.0,
            'q8_0' => 1.0625,
            'q4_0' => 0.5625,
        ],
        'apple_bandwidth_gbps' => [
            'M1 Pro' => 200,
            'M1 Max' => 400,
            'M1 Ultra' => 800,
            'M2 Pro' => 200,
            'M2 Ultra' => 800,
            'M3 Pro' => 150,
            'M4 Pro' => 273,
        ],
        'architectures' => [
            'llama3.1' => [
                '8b' => [
                    'parameters_billions' => 8.03,
                    'layers' => 32,
                    'kv_heads' => 8,
                    'head_dim' => 128,
                ],
                '70b' => [
                    'parameters_billions' => 70.6,
                    'layers' => 80,
                    'kv_heads' => 8,
                    'head_dim' => 128,
                ],
                '405b' => [
                    'parameters_billions' => 405.9,
                    'layers' => 126,
                    'kv_heads' => 8,
                    'head_dim' => 128,
                ],
            ],
            'llama3.3' => [
                '70b' => [
                    'parameters_billions' => 70.6,
                    'layers' => 80,
                    'kv_heads' => 8,
                    'head_dim' => 128,
                ],
            ],
        ],
        'profile' => [
            'prompts' => 'storage/samples/profile-set.json',
            'interval' => 1.0,
            'max_tokens' => 256,
        ],
    ],

    'compare' => [
        'defaults' => [
            'support.reply' => 'storage/golden/retrieval.jsonl',
            'ticket.classify' => 'storage/samples/tickets.json',
        ],
        'output' => 'storage/benchmarks',
        'replay_limit' => 50,
        'judge_max_tokens' => 200,
    ],

];
