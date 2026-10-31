<?php

use App\Shell\Integrations\Webhooks\DrainWebhooks;

return [
    'commands' => [DrainWebhooks::class],
    'defaults' => ['webhook_delivery' => env('INTEGRATIONS_WEBHOOK_DELIVERY_ENABLED', false) === true],
];
