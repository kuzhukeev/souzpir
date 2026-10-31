<?php

use App\Shell\Organizations\Middleware\RequireModule;
use App\Shell\Organizations\ModuleSharedProps;

return [
    'middleware_aliases' => ['module.enabled' => RequireModule::class],
    'shared_props' => [ModuleSharedProps::class],
];
