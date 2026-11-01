<?php

use App\Shell\Auth\SessionRegistered;
use App\Shell\MemberActivity\RecordCompletedLogin;

return [
    'listeners' => [SessionRegistered::class => [RecordCompletedLogin::class]],
];
