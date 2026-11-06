<?php

use App\Shell\Data\ExportCleanupSchedule;
use App\Shell\Data\PruneExportsCommand;
use App\Shell\Data\PruneLegacyExportLocksCommand;

return [
    'defaults' => [
        'export_ttl_minutes' => env('DATA_EXPORT_TTL_MINUTES', 43200),
        'export_orphan_grace_minutes' => env('DATA_EXPORT_ORPHAN_GRACE_MINUTES', 60),
    ],
    'commands' => [PruneExportsCommand::class, PruneLegacyExportLocksCommand::class],
    'schedules' => [ExportCleanupSchedule::class],
];
