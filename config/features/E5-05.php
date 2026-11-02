<?php

use App\Seller\Customers\NotifyDuePromises;
use App\Seller\Customers\PromiseSchedule;

return ['commands' => [NotifyDuePromises::class], 'schedules' => [PromiseSchedule::class]];
