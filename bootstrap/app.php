<?php

use App\Http\Middleware\EnsureLocalPreview;
use App\Http\Middleware\HandleInertiaRequests;
use App\Privacy\SupportExceptionHandler;
use App\Privacy\SupportRequestBoundary;
use App\Shell\Auth\EnforceAuthVersion;
use App\Shell\Auth\InvitationSecretBoundary;
use App\Shell\Auth\MfaCodeBoundary;
use App\Tenancy\ResolveTenant;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
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
        $middleware->prepend(MfaCodeBoundary::class);
        $middleware->prepend(SupportRequestBoundary::class);
        $middleware->redirectGuestsTo('/login');
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->web(append: [AuthenticateSession::class, EnforceAuthVersion::class, HandleInertiaRequests::class]);
        $middleware->alias(['preview.local' => EnsureLocalPreview::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

$app->singleton(ExceptionHandler::class, SupportExceptionHandler::class);

// Packages may prepend global middleware while providers boot. Keep support's
// private-input boundary outermost without changing the order of other entries.
$app->booted(function (Application $app): void {
    $kernel = $app->make(HttpKernel::class);
    $middleware = array_values(array_filter($kernel->getGlobalMiddleware(),
        fn ($item): bool => $item !== SupportRequestBoundary::class));
    array_unshift($middleware, SupportRequestBoundary::class);
    $kernel->setGlobalMiddleware($middleware);
});

$app->useStoragePath($runtime['storage']);
if (preg_match('/^[A-Za-z]:/', $basePath, $drive)) {
    $app->addAbsoluteCachePathPrefix(strtoupper($drive[0]));
    $app->addAbsoluteCachePathPrefix(strtolower($drive[0]));
}

return $app;
