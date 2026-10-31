<?php

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\ReportPreparation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/../../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../../bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    [, $org, $brand, $id, $base, $db] = $argv;
    if ($app->basePath() !== $base || DB::connection()->getDatabaseName() !== $db
        || ! str_starts_with($db, 'souzpir_test_') || $db !== config('runtime.test_database') || $db === config('runtime.preview_database')) {
        throw new RuntimeException('Unexpected test runtime.');
    }
    DB::statement("SET application_name = 'vision_snapshot_create_test'");
    echo "READY\n";
    flush();
    try {
        app(TenantContextRunner::class)->run(new TenantContext($org, $brand, source: 'job'), fn () => app(ReportPreparation::class)->snapshot($id));
        throw new RuntimeException('Oversized snapshot was accepted.');
    } catch (LogicException) {
        echo "DENIED\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'SNAPSHOT_CREATE_FAILED '.get_class($exception)."\n");
    exit(1);
}
