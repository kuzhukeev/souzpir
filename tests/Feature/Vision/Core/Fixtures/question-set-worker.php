<?php

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\QuestionSets;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/../../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../../bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    [, $org, $brand, $base, $db] = $argv;
    if ($app->basePath() !== $base || DB::connection()->getDatabaseName() !== $db
        || $db !== 'souzpir_test_sp_e8_01' || $db !== config('runtime.test_database') || $db === config('runtime.preview_database')) {
        throw new RuntimeException('Unexpected test runtime.');
    }
    DB::statement("SET application_name = 'vision_question_lock_test'");
    echo "READY\n";
    flush();
    try {
        app(TenantContextRunner::class)->run(new TenantContext($org, $brand, source: 'job'), fn () => app(QuestionSets::class)->create([
            ['id' => 'race', 'text' => 'Тестовый вопрос', 'intent' => 'choose'],
        ]));
        throw new RuntimeException('Question set unexpectedly created.');
    } catch (LogicException) {
        echo "DENIED\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'QUESTION_WORKER_FAILED '.get_class($exception)."\n");
    exit(1);
}
