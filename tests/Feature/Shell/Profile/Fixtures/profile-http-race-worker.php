<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Shell\Profile\ProfileAccess;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\IsolatedAuthDatabase;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
IsolatedAuthDatabase::assert();
$data = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['session.files' => $data['directory'], 'session.lottery' => [0, 100]]);
Http::preventStrayRequests();
Mail::fake();
DB::statement("SET lock_timeout = '15s'");
$wait = static function (string $barrier) use ($data): void {
    file_put_contents($data['directory'].'/'.$barrier, '1');
    $deadline = microtime(true) + 20;
    while (! is_file($data['directory'].'/release') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($data['directory'].'/release')) {
        throw new RuntimeException('Barrier timed out.');
    }
};
if ($data['hold'] === 'loaded') {
    User::retrieved(static function () use ($wait): void {
        static $paused = false;
        if (! $paused) {
            $paused = true;
            $wait('held');
        }
    });
}
if ($data['hold'] === 'lock') {
    DB::listen(static function (QueryExecuted $query) use ($wait): void {
        static $paused = false;
        $profileBoundary = false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === ProfileAccess::class && ($frame['function'] ?? null) === 'run') {
                $profileBoundary = true;
                break;
            }
        }
        if (! $paused && $profileBoundary && str_contains(strtolower($query->sql), 'auth_session_registry') && str_contains(strtolower($query->sql), 'for update')) {
            $paused = true;
            $wait('held');
        }
    });
}
[$method, $uri, $parameters] = match ($data['operation']) {
    'login' => ['POST', '/login', ['email' => 'profile-race@example.test', 'password' => 'race-password-strong']],
    'select', 'switch' => ['POST', '/brand/switch', ['brand_id' => $data['brand']]],
    'logout' => ['POST', '/logout', []],
    'get' => ['GET', '/profile', []],
    'put' => ['PUT', '/profile', ['name' => 'Race saved', 'phone' => null, 'timezone' => 'UTC', 'language' => 'ru']],
};
$request = Request::create($uri, $method, $parameters);
$request->server->set('SERVER_ADDR', '127.0.0.1');
if ($data['operation'] === 'get') {
    $request->headers->set('X-Inertia', 'true');
    $request->headers->set('X-Inertia-Version', app(HandleInertiaRequests::class)->version($request) ?? '');
}
if (isset($data['revision']) && in_array($data['operation'], ['get', 'put'], true)) {
    $request->headers->set('X-Brand-Revision', $data['revision']);
}
if ($data['cookie'] !== '') {
    $request->cookies->set(config('session.cookie'), base64_decode($data['cookie'], true));
}
file_put_contents($data['directory'].'/pid-'.$data['worker'], (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$cookie = null;
foreach ($response->headers->getCookies() as $responseCookie) {
    if ($responseCookie->getName() === config('session.cookie')) {
        $cookie = base64_encode($responseCookie->getValue());
    }
}
$body = $response->getContent();
$inertia = $data['operation'] === 'get' ? json_decode($body, true) : null;
echo json_encode(['status' => $response->getStatusCode(), 'cookie' => $cookie, 'location' => $response->headers->get('Location'), 'revision' => data_get($inertia, 'props.profileBrandRevision'), 'body' => $body], JSON_THROW_ON_ERROR);
