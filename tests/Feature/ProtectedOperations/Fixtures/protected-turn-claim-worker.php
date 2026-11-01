<?php

declare(strict_types=1);

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\User;
use App\ProtectedOperations\FirstTurnProtectedClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = env('DB_TEST_DATABASE');
$db = DB::selectOne('select current_database() as name, host(inet_server_addr()) as host, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
    || config('database.connections.pgsql.database') !== $expected || $db->name !== $expected || env('APP_PREVIEW_DATABASE') === $expected
    || (! str_starts_with((string) $db->host, '127.') && $db->host !== '::1') || $db->rolsuper || $db->rolcreatedb || $db->rolcreaterole
    || realpath($app->basePath()) !== realpath(dirname(__DIR__, 4))) {
    throw new RuntimeException('An isolated test worker is required.');
}
Http::preventStrayRequests();
Http::fake();
Mail::fake();
Queue::fake();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user']);
$session = new Store('c02-claim-worker', new ArraySessionHandler(120));
$session->setId($input['session']);
$session->start();
$session->put('auth.version', $user->auth_version);
$http = Request::create('/', 'POST');
$http->setLaravelSession($session);
$http->setUserResolver(fn () => $user);
$tenant = new TenantContext($input['org'], $input['brand'], $user->id, $input['member']);
$command = new TurnCommand($input['conversation'], $input['message'], $input['text'], null, $input['key'], 0);
file_put_contents($argv[1], (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
try {
    $claim = app(TenantContextRunner::class)->run($tenant, fn () => app(FirstTurnProtectedClaim::class)->claim($http, $command));
    echo json_encode(['status' => $claim->status, 'turn_id' => $claim->turnId], JSON_THROW_ON_ERROR);
} catch (IdempotencyConflict) {
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Protected claim worker failed.');
    exit(1);
}
