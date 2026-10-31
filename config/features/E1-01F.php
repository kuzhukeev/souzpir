<?php

use App\Shell\Auth\PasswordDelivery\DisabledTransport;
use App\Shell\Auth\PasswordDelivery\DispatchPending;
use App\Shell\Auth\PasswordDelivery\PasswordTransport;

return [
    'defaults' => ['enabled' => false, 'smtp_enabled' => false],
    'bindings' => [PasswordTransport::class => DisabledTransport::class],
    'commands' => [DispatchPending::class],
];
