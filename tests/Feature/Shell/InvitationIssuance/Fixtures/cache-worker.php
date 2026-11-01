<?php

use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$key = config('features.E1-06B.defaults.attempt_hmac_key');
echo json_encode([
    'cached' => app()->configurationIsCached(),
    'key_matches' => is_string($key) && hash_equals(getenv('E106B_EXPECTED_KEY_HASH'), hash('sha256', $key)),
    'route_registered' => app('router')->getRoutes()->getByName('team.invitations.issue') !== null,
], JSON_THROW_ON_ERROR);
