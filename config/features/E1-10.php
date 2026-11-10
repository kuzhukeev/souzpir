<?php

use App\Shell\Integrations\PruneSecretReceiptsCommand;
use App\Shell\Integrations\SecretReceiptsCleanupSchedule;
use App\Shell\Integrations\Webhooks\DrainWebhooks;

return [
    'commands' => [DrainWebhooks::class, PruneSecretReceiptsCommand::class],
    'schedules' => [SecretReceiptsCleanupSchedule::class],
    'defaults' => [
        'webhook_delivery' => env('INTEGRATIONS_WEBHOOK_DELIVERY_ENABLED', false) === true,
        'company_notification_delivery' => env('COMPANY_NOTIFICATION_DELIVERY_ENABLED', false) === true,
        // Explicit opt-in only for a local single-node installation with file-backed locks.
        'single_node_locks' => env('INTEGRATIONS_SINGLE_NODE_LOCKS', false) === true,
    ],
];
