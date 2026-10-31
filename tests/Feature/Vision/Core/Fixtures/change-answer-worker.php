<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
[$script, $id, $expectedBase, $expectedDatabase] = $argv;
if ($app->basePath() !== $expectedBase || DB::connection()->getDatabaseName() !== $expectedDatabase || ! str_starts_with($expectedDatabase, 'souzpir_test_')
    || $expectedDatabase !== config('runtime.test_database') || $expectedDatabase === config('runtime.preview_database')) {
    throw new LogicException('Unexpected test runtime.');
}
DB::table('measurement_answers')->where('id', $id)->update(['answer_text' => 'Изменено до сохранения факта']);
