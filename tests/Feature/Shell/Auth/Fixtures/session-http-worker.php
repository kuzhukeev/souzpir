<?php

use App\Shell\Auth\AuthSessionRegistry;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
[$script, $mode, $cookie, $barrier] = $argv;
if ($mode === 'slow') {
    Route::middleware(['web', 'auth'])->get('/_session-race', function () use ($barrier) {
        file_put_contents($barrier.'.loaded', '1');
        $deadline = microtime(true) + 20;
        while (! is_file($barrier.'.release') && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($barrier.'.release')) {
            abort(500, 'barrier timeout');
        }

        return response('slow completed');
    });
}
if ($mode === 'sensitive') {
    Route::middleware(['web', 'auth'])->get('/_session-sensitive', function (Request $request) use ($barrier) {
        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $barrier) {
            file_put_contents($barrier.'.locked', '1');
            $deadline = microtime(true) + 20;
            while (! is_file($barrier.'.release') && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! is_file($barrier.'.release')) {
                abort(500, 'barrier timeout');
            }
            DB::table('users')->where('id', $request->user()->id)->update(['name' => 'Action committed']);

            return response('committed');
        });
    });
}
[$method, $uri, $parameters] = match ($mode) {
    'login' => ['POST', '/login', ['email' => 'race-owner@example.test', 'password' => 'race-password-strong']],
    'login-remember' => ['POST', '/login', ['email' => 'race-owner@example.test', 'password' => 'race-password-strong', 'remember' => true]],
    'login-second' => ['POST', '/login', ['email' => 'second@example.test', 'password' => 'race-password-strong']],
    'slow' => ['GET', '/_session-race', []],
    'sensitive' => ['GET', '/_session-sensitive', []],
    'logout' => ['POST', '/logout', []],
    'view' => ['GET', '/auth/ready', []],
    'remember' => ['GET', '/auth/ready', []],
};
$request = Request::create($uri, $method, $parameters);
if (in_array($mode, ['view', 'remember'], true)) {
    $request->headers->set('X-Inertia', 'true');
}
if ($cookie !== '-') {
    $name = $mode === 'remember' ? Auth::guard()->getRecallerName() : config('session.cookie');
    $request->cookies->set($name, base64_decode($cookie, true));
}
$kernel = $app->make(HttpKernel::class);
if ($mode === 'logout') {
    file_put_contents($barrier.'.logout-started', '1');
}
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$sessionCookie = null;
$rememberCookie = null;
foreach ($response->headers->getCookies() as $responseCookie) {
    if ($responseCookie->getName() === config('session.cookie')) {
        $sessionCookie = base64_encode($responseCookie->getValue());
    }
    if ($responseCookie->getName() === Auth::guard()->getRecallerName()) {
        $rememberCookie = base64_encode($responseCookie->getValue());
    }
}
echo json_encode(['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'cookie' => $sessionCookie, 'remember' => $rememberCookie, 'session_id' => $request->session()->getId()], JSON_THROW_ON_ERROR);
