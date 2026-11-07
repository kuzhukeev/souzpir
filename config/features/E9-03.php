<?php

use App\Shadow\Console\RecordShadowComparisonCommand;
use App\Shadow\Console\ShadowDiscrepancyReportCommand;

return [
    'defaults' => ['shadow_enabled' => false],
    'commands' => [RecordShadowComparisonCommand::class, ShadowDiscrepancyReportCommand::class],
];
