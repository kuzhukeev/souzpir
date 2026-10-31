<?php

use App\Shell\Integrations\Webhooks\DrainWebhooks;

return [
    'commands' => [DrainWebhooks::class],
    'defaults' => [
        'webhook_delivery' => env('INTEGRATIONS_WEBHOOK_DELIVERY_ENABLED', false) === true,
        // Explicit opt-in only for a local single-node installation with file-backed locks.
        'single_node_locks' => env('INTEGRATIONS_SINGLE_NODE_LOCKS', false) === true,
    ],
];
