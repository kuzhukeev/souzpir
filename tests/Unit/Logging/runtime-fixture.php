<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Log;

$_SERVER['argv'] = ['artisan', $argv[1], '--env=testing'];
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo config('logging.default')."\n";
if (config('logging.default') === 'runtime') {
    Log::error('synthetic-secret /private/path', ['exception' => new RuntimeException('synthetic-password')]);
    echo storage_path('logs/runtime.log');
}
