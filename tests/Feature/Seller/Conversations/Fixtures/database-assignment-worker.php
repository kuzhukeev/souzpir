<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 5).'/vendor/autoload.php';
if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Testing environment required.\n");
    exit(2);
}
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'pgsql'
    || ! preg_match('/(?:^|_)test(?:_|$)/', $connection->getDatabaseName())
    || $connection->getDatabaseName() !== config('runtime.test_database')
    || $connection->getDatabaseName() === config('runtime.preview_database')
    || $connection->selectOne('SELECT current_database() AS name')->name !== $connection->getDatabaseName()) {
    fwrite(STDERR, "Unsafe test database.\n");
    exit(2);
}
[$script, $kind, $id, $change, $value] = $argv;
fwrite(STDOUT, 'ready:'.DB::scalar('SELECT pg_backend_pid()')."\n");
fgets(STDIN);
try {
    DB::transaction(function () use ($kind, $id, $change, $value): void {
        $table = match ($kind) {
            'membership' => 'memberships',
            'conversation' => 'conversations',
            'task' => 'seller_tasks',
            'brand' => 'brands',
            'organization' => 'organizations',
        };
        DB::table($table)->where('id', $id)->update([$change => $value]);
    });
    fwrite(STDOUT, "accepted\n");
} catch (QueryException $exception) {
    fwrite(STDOUT, ($exception->errorInfo[0] ?? 'unknown')."\n");
}
