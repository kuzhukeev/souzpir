<?php

declare(strict_types=1);

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\User;
use App\ProtectedOperations\PostgresSessionIdempotency;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = env('DB_TEST_DATABASE');
if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
    || DB::selectOne('select current_database() as name')->name !== $expected
    || config('database.connections.pgsql.database') !== $expected || env('APP_PREVIEW_DATABASE') === $expected
    || realpath($app->basePath()) !== realpath(dirname(__DIR__, 4))) {
    throw new RuntimeException('Unsafe isolated worker.');
}
DB::statement("SET application_name = 'c02_session_idempotency_worker'");
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user']);
$session = new Store('c02-session-worker', new ArraySessionHandler(120));
$session->setId($input['session']);
$session->start();
$session->put('auth.version', $input['version']);
$request = Request::create('/', 'POST');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
try {
    $result = app(PostgresSessionIdempotency::class)->execute($request, 'test.session', 'synthetic-key', $input['body'], function () use ($user): array {
        DB::table('users')->where('id', $user->id)->update(['name' => DB::raw("name || 'x'")]);

        return ['value' => 'child'];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (IdempotencyConflict) {
    echo json_encode(['conflict' => true], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Session idempotency worker failed.');
    exit(1);
}
