<?php

use App\Vision\Usage\MeasurementUsage;

return ['extensions' => env('VISION_USAGE_ENABLED', false) ? ['usage.vision_measurement' => MeasurementUsage::class] : []];
