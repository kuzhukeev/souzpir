<?php

use App\Seller\Settings\Tools\HandoffSchedule;
use App\Seller\Settings\Tools\MonitorHandoffs;

return ['commands' => [MonitorHandoffs::class], 'schedules' => [HandoffSchedule::class]];
