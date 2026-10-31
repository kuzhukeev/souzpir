<?php

use App\Vision\Core\WorkloadLimits;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/../../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../../bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    [, $id, $base, $db] = $argv;
    if ($app->basePath() !== $base || DB::connection()->getDatabaseName() !== $db
        || ! str_starts_with($db, 'souzpir_test_') || $db !== config('runtime.test_database') || $db === config('runtime.preview_database')) {
        throw new RuntimeException('Unexpected test runtime.');
    }
    DB::statement("SET application_name = 'vision_snapshot_answer_test'");
    echo "READY\n";
    flush();
    $changed = DB::table('measurement_answers')->where('id', $id)->update(['answer_text' => str_repeat('x', WorkloadLimits::dataBytes() + 1)]);
    if ($changed !== 1) {
        throw new RuntimeException('Answer was not changed.');
    }
    echo "COMMITTED\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'SNAPSHOT_WORKER_FAILED '.get_class($exception)."\n");
    exit(1);
}
