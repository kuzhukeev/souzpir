<?php

use App\Http\Middleware\EnsureLocalPreview;
use App\Http\Middleware\HandleInertiaRequests;
use App\Shell\Auth\EnforceAuthVersion;
use App\Shell\Auth\InvitationSecretBoundary;
use App\Tenancy\ResolveTenant;
use App\Tenancy\TokenApi\BearerBoundary;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;

$basePath = dirname(__DIR__);
require_once __DIR__.'/runtime.php';
$runtime = prepareRuntime($basePath);

$app = Application::configure(basePath: $basePath)
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(InvitationSecretBoundary::class);
        $middleware->prepend(BearerBoundary::class);
        $middleware->redirectGuestsTo('/login');
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->web(append: [AuthenticateSession::class, EnforceAuthVersion::class, HandleInertiaRequests::class]);
        $middleware->alias(['preview.local' => EnsureLocalPreview::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

$app->useStoragePath($runtime['storage']);
if (preg_match('/^[A-Za-z]:/', $basePath, $drive)) {
    $app->addAbsoluteCachePathPrefix(strtoupper($drive[0]));
    $app->addAbsoluteCachePathPrefix(strtolower($drive[0]));
}

return $app;
