<?php

use App\Seller\Live\QueueLiveDraft;

return [
    'listeners' => ['eloquent.created: App\Seller\Conversations\Models\Message' => [QueueLiveDraft::class]],
    'defaults' => [
        'enabled' => filter_var(env('LIVE_DRAFT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'model' => env('LIVE_DRAFT_MODEL', 'anthropic/claude-sonnet-5.5'),
        'temperature' => (float) env('LIVE_DRAFT_TEMPERATURE', 0.4),
        'max_tokens' => (int) env('LIVE_DRAFT_MAX_TOKENS', 500),
        'history_messages' => 12,
        'max_knowledge_bytes' => 60000,
    ],
];
