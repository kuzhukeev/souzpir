<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$base = realpath($app->basePath());
$actual = DB::selectOne('select current_database() as name, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
$testDatabase = config('runtime.test_database');
if (! $app->environment('testing') || ! $base || strcasecmp($base, (string) realpath(getcwd())) !== 0
    || ! is_string($testDatabase) || ! preg_match('/(?:^|_)test(?:_|$)/', $testDatabase)
    || $actual->name !== $testDatabase || $actual->name === config('runtime.preview_database')
    || (! str_starts_with((string) $actual->host, '127.') && $actual->host !== '::1')
    || (int) $actual->port !== (int) config('database.connections.pgsql.port')
    || $actual->rolsuper || $actual->rolcreatedb || $actual->rolcreaterole
    || config('session.driver') !== 'database' || config('cache.default') !== 'database') {
    fwrite(STDERR, 'Isolated shared-session worker guard failed.');
    exit(1);
}
Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/_e110-select-brand', fn () => response('', 204));
if (in_array($input['mode'], ['first', 'logout-first', 'revoke-first'], true)) {
    DB::listen(function (QueryExecuted $query) use ($input): void {
        static $paused = false;
        $sql = strtolower($query->sql);
        $target = match ($input['mode']) {
            'first' => 'integration_secret_receipts',
            'logout-first' => 'auth_session_registry',
            'revoke-first' => 'personal_access_tokens',
        };
        $verb = $input['mode'] === 'revoke-first' ? 'delete' : 'update';
        if ($paused || ! str_starts_with(ltrim($sql), $verb) || ! str_contains($sql, $target)) {
            return;
        }
        $paused = true;
        file_put_contents($input['barrier'].'.locked', '1');
        $deadline = microtime(true) + 15;
        while (! is_file($input['barrier'].'.release') && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($input['barrier'].'.release')) {
            throw new RuntimeException('Worker barrier timed out.');
        }
    });
}
[$method, $path, $data] = match ($input['mode']) {
    'login' => ['POST', '/login', ['email' => 'e110-race@example.test', 'password' => 'race-password-strong']],
    'select' => ['GET', '/_e110-select-brand', []],
    'create', 'outer' => ['POST', '/integrations/api/keys', ['name' => 'Race fixture', 'abilities' => ['read']]],
    'revoke-first' => ['DELETE', '/integrations/api/keys/'.(string) DB::table('personal_access_tokens')->value('id'), []],
    'first', 'second' => ['POST', '/integrations/api/secrets', ['type' => 'api_key']],
    'logout', 'logout-first' => ['POST', '/logout', []],
};
$request = Request::create('https://localhost'.$path, $method, $data, server: ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
$request->headers->set('Accept', 'application/json');
if (is_string($input['cookie'] ?? null)) {
    $request->cookies->set(config('session.cookie'), base64_decode($input['cookie'], true));
}
if (in_array($input['mode'], ['second', 'logout'], true)) {
    file_put_contents($input['barrier'].'.second-started', '1');
}
$kernel = $app->make(HttpKernel::class);
if ($input['mode'] === 'outer') {
    DB::beginTransaction();
}
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
if ($input['mode'] === 'outer') {
    DB::rollBack();
}
$cookie = null;
foreach ($response->headers->getCookies() as $item) {
    if ($item->getName() === config('session.cookie')) {
        $cookie = base64_encode($item->getValue());
    }
}
$payload = json_decode($response->getContent(), true);
echo json_encode(['status' => $response->getStatusCode(), 'cookie' => $cookie,
    'has_value' => is_array($payload) && is_string($payload['value'] ?? null)], JSON_THROW_ON_ERROR);
