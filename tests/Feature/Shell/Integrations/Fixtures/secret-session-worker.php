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
$base = str_replace('\\', '/', realpath($app->basePath()));
$database = DB::selectOne('select current_database() as name')->name;
if (! $app->environment('testing') || ! str_ends_with(strtolower($base), '/.worktrees/sp-e1-10') || $database !== 'souzpir_test_sp_e1_10'
    || config('session.driver') !== 'database' || config('cache.default') !== 'database') {
    fwrite(STDERR, 'Isolated shared-session worker guard failed.');
    exit(1);
}
Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/_e110-select-brand', fn () => response('', 204));
if ($input['mode'] === 'first') {
    DB::listen(function (QueryExecuted $query) use ($input): void {
        static $paused = false;
        if ($paused || ! str_contains($query->sql, 'auth_session_registry') || ! str_contains(strtolower($query->sql), 'for update')) {
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
    'first', 'second' => ['POST', '/integrations/api/secrets', ['type' => 'api_key']],
};
$request = Request::create('https://localhost'.$path, $method, $data, server: ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
$request->headers->set('Accept', 'application/json');
if (is_string($input['cookie'] ?? null)) {
    $request->cookies->set(config('session.cookie'), base64_decode($input['cookie'], true));
}
if ($input['mode'] === 'second') {
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
