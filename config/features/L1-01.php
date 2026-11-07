<?php

use App\Ai\Budget\AiBudgetCommand;
use App\Ai\Budget\AiBudgetSetOrgCommand;

return [
    'commands' => [AiBudgetCommand::class, AiBudgetSetOrgCommand::class],
    'defaults' => [
        // Global hard ceilings for the live OpenRouter launch; env-overridable.
        'global_daily_usd' => (float) env('AI_BUDGET_DAILY_USD', 3),
        'global_monthly_usd' => (float) env('AI_BUDGET_MONTHLY_USD', 30),
        // Default organization monthly cap when no ai:budget:set-org override exists.
        'org_monthly_usd' => (float) env('AI_BUDGET_ORG_MONTHLY_USD', 30),
        'budget_threshold_basis_points' => 8000,
        // Per-call reservation ceilings (micros = 1e-6 USD), a technical safety bound
        // for the models actually allowed to go live, not a published AI price.
        'reservation_micros' => [
            'anthropic/claude-sonnet-5.5' => 50000,
            'google/gemini-2.5-flash' => 20000,
        ],
        'reservation_request_limits' => [
            'anthropic/claude-sonnet-5.5' => ['max_input_bytes' => 120000, 'max_output_tokens' => 700],
            'google/gemini-2.5-flash' => ['max_input_bytes' => 200000, 'max_output_tokens' => 1200],
        ],
    ],
];
