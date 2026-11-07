<?php

use App\Tenancy\Console\OrgCreateCommand;

return [
    'commands' => [OrgCreateCommand::class],
    'defaults' => ['default_plan' => 'start'],
];
