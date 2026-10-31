<?php

use App\Shell\Data\ExportCleanupSchedule;
use App\Shell\Data\PruneExportsCommand;

return [
    'defaults' => [
        'export_ttl_minutes' => env('DATA_EXPORT_TTL_MINUTES', 1440),
        'export_orphan_grace_minutes' => env('DATA_EXPORT_ORPHAN_GRACE_MINUTES', 60),
    ],
    'commands' => [PruneExportsCommand::class],
    'schedules' => [ExportCleanupSchedule::class],
];
