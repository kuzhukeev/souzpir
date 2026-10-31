<?php

use App\Shell\Auth\MfaChallenge;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = basename(dirname(base_path())) === '.worktrees' ? 'souzpir_test_'.str_replace('-', '_', basename(base_path())) : 'souzpir_test_main';
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $expected
    || DB::connection()->getDatabaseName() !== config('runtime.test_database')
    || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new LogicException('Isolated test database required.');
}
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
fwrite(STDOUT, 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n");
try {
    $user = app(MfaChallenge::class)->verify($data['pending'], $data['safe']);
    fwrite(STDOUT, $user ? "accepted\n" : "denied\n");
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error)."\n");
    exit(1);
}
