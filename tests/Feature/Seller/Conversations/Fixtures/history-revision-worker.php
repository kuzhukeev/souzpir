<?php

use App\Seller\Channels\Core\InboundRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = env('DB_TEST_DATABASE');
if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
    || DB::selectOne('select current_database() as name')->name !== $expected
    || config('database.connections.pgsql.database') !== $expected || env('APP_PREVIEW_DATABASE') === $expected
    || realpath($app->basePath()) !== realpath(__DIR__.'/../../../../..')) {
    throw new RuntimeException('Unsafe isolated worker.');
}
[$file, $operation, $org, $brand, $conversation, $channel, $marker] = $argv;
DB::statement("SET application_name = 'c02_history_worker'");
file_put_contents($marker, (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
try {
    if ($operation === 'inbound') {
        $app->make(InboundRecorder::class)->ingest(new TenantContext($org, $brand, source: 'job'), $channel, 'synthetic-external-id', [], $conversation, 'Synthetic concurrent input');
    } elseif ($operation === 'direct') {
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'conversation_id' => $conversation, 'role' => 'system', 'text' => 'Synthetic concurrent input', 'meta' => '{}']);
    } else {
        throw new RuntimeException('Invalid synthetic test operation.');
    }
    echo 'inserted';
} catch (QueryException $error) {
    if (($error->errorInfo[0] ?? null) !== '55P03') {
        throw $error;
    }
    echo 'retry_required';
}
