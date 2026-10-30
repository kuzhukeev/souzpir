<?php

use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\Contracts\UsageReader;
use App\Shell\Plans\DatabasePlanLimits;
use App\Shell\Plans\RegisteredUsageReader;

return ['bindings' => [PlanLimits::class => DatabasePlanLimits::class, UsageReader::class => RegisteredUsageReader::class]];
