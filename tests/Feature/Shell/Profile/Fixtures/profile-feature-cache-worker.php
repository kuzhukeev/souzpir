<?php

use App\Shell\Profile\ProfileSettingsAvailability;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\MfaTestDatabase;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
MfaTestDatabase::assert();
$route = app('router')->getRoutes()->getByName('profile.show');
echo json_encode([
    'config_cached' => app()->configurationIsCached(),
    'routes_cached' => app()->routesAreCached(),
    'configured' => app(ProfileSettingsAvailability::class)->configured(),
    'ready' => app(ProfileSettingsAvailability::class)->ready(),
    'guarded_route' => $route !== null && in_array('profile.enabled', $route->gatherMiddleware(), true),
], JSON_THROW_ON_ERROR);
