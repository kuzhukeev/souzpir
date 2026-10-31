<?php

use App\Ai\Gateway;
use App\Contracts\Ai\AiGateway;
use Database\Seeders\Ai\PromptSetsSeeder;
use Database\Seeders\Ai\ProviderSeeder;

return [
    'bindings' => [AiGateway::class => Gateway::class],
    'seeders' => [ProviderSeeder::class, PromptSetsSeeder::class],
    'defaults' => [
        'models' => [
            'google/gemini-2.5-flash' => ['provider' => 'gemini', 'status' => 'active'],
            'anthropic/claude-sonnet-5.5' => ['provider' => 'claude', 'status' => 'active'],
            'anthropic/claude-haiku-5.5' => ['provider' => 'claude', 'status' => 'active'],
            'openai/gpt-6.1-sol' => ['provider' => 'chatgpt', 'status' => 'active'],
            'perplexity/sonar' => ['provider' => 'perplexity', 'status' => 'active'],
            'google/gemini-3.8-flash' => ['provider' => 'gemini', 'status' => 'active'],
            'gigachat' => ['provider' => 'gigachat', 'status' => 'planned'],
            'yandexgpt' => ['provider' => 'yandexgpt', 'status' => 'planned'],
            'alice' => ['provider' => 'alice', 'status' => 'planned'],
            'neuro' => ['provider' => 'neuro', 'status' => 'planned'],
        ],
        // Seller prototype config/agent.php defaults; the adapter must retain purpose names.
        'seller_defaults' => [
            'extractor' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0],
            'responder' => ['model' => 'anthropic/claude-sonnet-5.5', 'temperature' => 0.7],
            'guard' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0],
            'summary' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.2],
            'simulator' => ['model' => 'anthropic/claude-haiku-5.5'],
            'judge' => ['model' => 'anthropic/claude-sonnet-5.5'],
        ],
        // No prices or conversion coefficients are invented. Operator may configure bounds.
        'reservation_micros' => [],
        'reservation_request_limits' => [],
        'budget_threshold_basis_points' => null,
        'live_enabled' => (bool) env('AI_LIVE_ENABLED', false),
        'openrouter_key' => env('OPENROUTER_API_KEY'),
    ],
];
