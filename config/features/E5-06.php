<?php

use App\Seller\Channels\Planned\ContactReminderCommand;
use App\Seller\Channels\Planned\ContactReminderSchedule;

return [
    'commands' => [ContactReminderCommand::class],
    'schedules' => [ContactReminderSchedule::class],
];
