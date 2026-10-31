<?php

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = env('DB_TEST_DATABASE');
if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
    || DB::selectOne('select current_database() as name')->name !== $expected
    || config('database.connections.pgsql.database') !== $expected || env('APP_PREVIEW_DATABASE') === $expected
    || realpath($app->basePath()) !== realpath(__DIR__.'/../../../..')) {
    throw new RuntimeException('Unsafe isolated worker.');
}
[, $org, $brand, $body, $marker] = $argv;
DB::statement("SET application_name = 'c02_idempotency_worker'");
file_put_contents($marker, (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
try {
    $result = $app->make(PostgresTransactionalIdempotency::class)->execute($org, $brand, 'test.write', 'synthetic-key', $body, function () use ($brand): array {
        DB::table('brands')->where('id', $brand)->update(['name' => DB::raw("name || 'x'")]);

        return ['answer' => 'from-worker'];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (IdempotencyConflict) {
    echo 'conflict';
}
