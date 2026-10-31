<?php

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\MeasurementRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
[$script, $organization, $brand, $answer, $fixtures, $barrier, $worker, $expectedBase, $expectedDatabase] = $argv;
if ($app->basePath() !== $expectedBase || DB::connection()->getDatabaseName() !== $expectedDatabase
    || ! str_contains(strtolower($expectedDatabase), 'test')) {
    throw new RuntimeException('Unexpected test runtime.');
}
config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $fixtures]);
Http::preventStrayRequests();
file_put_contents($barrier.'.'.$worker, 'ready');
$deadline = microtime(true) + 45;
while (! is_file($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout.');
    }
    usleep(10000);
}
$status = app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'),
    fn (): string => app(MeasurementRunner::class)->measure($answer));
echo $status;
