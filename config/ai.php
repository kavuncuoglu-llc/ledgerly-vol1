<?php

return [

    'default' => env('AI_DEFAULT_PROVIDER', 'local'),

    'providers' => [
        'local' => [
            'driver' => env('AI_DRIVER', 'openai-compatible'),
            'base_url' => env('AI_BASE_URL', 'http://localhost:11434/v1'),
            'api_key' => env('AI_API_KEY', 'ollama'),
            'model' => env('AI_MODEL', 'llama3.1:8b'),
            'cache_breakpoints' => env('AI_CACHE_BREAKPOINTS') === null
                ? null
                : filter_var(env('AI_CACHE_BREAKPOINTS'), FILTER_VALIDATE_BOOLEAN),
        ],
        'local-embed' => [
            'driver' => 'openai-compatible',
            'base_url' => env('AI_BASE_URL', 'http://localhost:11434/v1'),
            'api_key' => env('AI_API_KEY', 'ollama'),
            'model' => env('AI_EMBED_MODEL', 'nomic-embed-text'),
            'dimensions' => (int) env('AI_EMBED_DIMENSIONS', 768),
            'prefixes' => [
                'query' => 'search_query: ',
                'document' => 'search_document: ',
            ],
        ],
        'vision' => [
            'driver' => 'openai-compatible',
            'base_url' => env('AI_BASE_URL', 'http://localhost:11434/v1'),
            'api_key' => env('AI_API_KEY', 'ollama'),
            'model' => env('AI_VISION_MODEL', 'llama3.2-vision:11b'),
            'vision' => true,
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

    'features' => [
        'ticket.classify' => [
            'provider' => 'local',
            'profile' => 'deterministic',
        ],
        'ticket.extract' => [
            'provider' => 'local',
            'profile' => 'deterministic',
        ],
        'support.reply' => [
            'provider' => 'local',
            'profile' => 'assistant',
            'escalate_to' => 'frontier',
        ],
        'invoice.extract' => [
            'provider' => 'vision',
            'profile' => 'deterministic',
        ],
        'conversation.summarize' => [
            'provider' => 'local',
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
    ],

];
