<?php

use App\Seller\Followups\FollowupSchedule;
use App\Seller\Followups\ScheduleFollowups;

return ['commands' => [ScheduleFollowups::class], 'schedules' => [FollowupSchedule::class]];
