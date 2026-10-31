<?php

use App\Shell\Auth\Retention\PruneSessions;

return [
    'defaults' => ['retention_days' => env('SESSION_REGISTRY_RETENTION_DAYS', 90)],
    'commands' => [PruneSessions::class],
];
