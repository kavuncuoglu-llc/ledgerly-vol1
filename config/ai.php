<?php

return [

    'token_counter' => env('AI_TOKEN_COUNTER', 'ratio'),

    'budget' => [
        'window' => (int) env('AI_CONTEXT_WINDOW', 8192),
        'answer' => 400,
        'system' => 450,
        'margin_pct' => 0.05,
        'context' => 3500,
    ],

    'rates' => [
        'ollama' => [
            'llama3.1:8b' => [
                'in' => 0,
                'out' => 0,
            ],
        ],
    ],

];
