<?php

use App\Http\Middleware\EnsureLocalPreview;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$basePath = dirname(__DIR__);
require_once __DIR__.'/runtime.php';
$runtime = prepareRuntime($basePath);

$app = Application::configure(basePath: $basePath)
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/login');
        $middleware->web(append: [HandleInertiaRequests::class]);
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
