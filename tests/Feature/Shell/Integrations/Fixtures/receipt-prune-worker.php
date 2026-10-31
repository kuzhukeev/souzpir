<?php

use App\Shell\Integrations\PruneSecretReceipts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$root = realpath($app->basePath());
$actual = DB::selectOne('select current_database() as name, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
$testDatabase = config('runtime.test_database');
$tmp = realpath($app->basePath('.local/tmp'));
$barrier = $input['barrier'] ?? '';
if (! $app->environment('testing') || ! $root || strcasecmp($root, (string) realpath(getcwd())) !== 0
    || ! $tmp || ! is_string($barrier) || strcasecmp((string) realpath(dirname($barrier)), $tmp) !== 0
    || ! preg_match('/^e110-prune-[a-zA-Z0-9]{16}$/D', basename($barrier))
    || ! is_string($testDatabase) || ! preg_match('/(?:^|_)test(?:_|$)/', $testDatabase)
    || $actual->name !== $testDatabase || $actual->name === config('runtime.preview_database')
    || (! str_starts_with((string) $actual->host, '127.') && $actual->host !== '::1')
    || (int) $actual->port !== (int) config('database.connections.pgsql.port')
    || $actual->rolsuper || $actual->rolcreatedb || $actual->rolcreaterole) {
    throw new RuntimeException('Isolated receipt cleanup worker guard failed.');
}
$pause = static function () use ($barrier): void {
    file_put_contents($barrier.'.locked', '1');
    $deadline = microtime(true) + 15;
    while (! is_file($barrier.'.release') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier.'.release')) {
        throw new RuntimeException('Receipt test barrier timed out.');
    }
};
if (($input['mode'] ?? null) === 'lock') {
    $hash = $input['hash'] ?? '';
    if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
        throw new RuntimeException('Invalid test receipt.');
    }
    DB::transaction(static function () use ($hash, $pause): void {
        if (! DB::table('integration_secret_receipts')->where('nonce_hash', $hash)->lockForUpdate()->first()) {
            throw new RuntimeException('Missing test receipt.');
        }
        $pause();
    });
    echo 'locked-released';

    return;
}
if (($input['mode'] ?? null) === 'prune') {
    DB::listen(static function (QueryExecuted $query) use ($pause): void {
        static $paused = false;
        if (! $paused && str_contains($query->sql, 'integration_secret_receipts') && str_contains(strtolower($query->sql), 'for update skip locked')) {
            $paused = true;
            $pause();
        }
    });
    echo app(PruneSecretReceipts::class)->prune(2);

    return;
}
throw new RuntimeException('Invalid receipt test mode.');
