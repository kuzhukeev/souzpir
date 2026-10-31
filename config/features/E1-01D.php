<?php

use App\Shell\Auth\VerificationDelivery\DisabledTransport;
use App\Shell\Auth\VerificationDelivery\VerificationTransport;

return [
    // This feature deliberately has no real transport binding until operator integration.
    'defaults' => ['enabled' => false, 'smtp_enabled' => false],
    'bindings' => [VerificationTransport::class => DisabledTransport::class],
];
