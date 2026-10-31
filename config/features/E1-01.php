<?php

use App\Shell\Auth\Console\PrepareAuthBrowserFixture;
use App\Shell\Auth\EnforceAuthVersion;
use App\Shell\Auth\NoStore;

return [
    'commands' => [PrepareAuthBrowserFixture::class],
    'middleware_aliases' => ['auth.version' => EnforceAuthVersion::class, 'auth.nostore' => NoStore::class],
    'defaults' => [
        'bootstrap_token_hash' => env('AUTH_BOOTSTRAP_TOKEN_HASH'),
        'verification_enabled' => (bool) env('AUTH_EMAIL_DELIVERY_ENABLED', false),
    ],
];
