<?php

use App\Seller\Catalog\RefreshYmlFeeds;
use App\Seller\Catalog\YmlSchedule;

return ['commands' => [RefreshYmlFeeds::class], 'schedules' => [YmlSchedule::class]];
