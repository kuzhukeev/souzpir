<?php

namespace App\Privacy;

use Closure;
use Illuminate\Http\Request;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Protect support request payloads before routing, CSRF, and tenant middleware. */
final class SupportRequestBoundary
{
    public static function applies(Request $request): bool
    {
        if (! $request->isMethod('POST') && $request->getRealMethod() !== 'POST') {
            return false;
        }

        // Laravel's URI matcher decodes the path exactly once and ignores trailing slashes.
        $path = trim($request->decodedPath(), '/');

        return $path === 'help/support' || str_starts_with($path, 'help/support/');
    }

    public function handle(#[\SensitiveParameter] Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }

        $prior = ini_get('zend.exception_ignore_args');
        $enabled = in_array($prior, ['1', 'On', 'on'], true);
        // Octane/coroutine workers can serve requests concurrently in one process. Never
        // mutate a process-wide ini option in that mode: require it to be enabled up front.
        $longLived = isset($_SERVER['LARAVEL_OCTANE']) || getenv('LARAVEL_OCTANE') !== false
            || isset($_SERVER['OCTANE_SERVER']) || getenv('OCTANE_SERVER') !== false
            || isset($_SERVER['RR_MODE']) || getenv('RR_MODE') !== false
            || class_exists('Swoole\\Coroutine', false)
            || (! in_array(PHP_SAPI, ['apache2handler', 'cgi-fcgi', 'fpm-fcgi', 'cli-server'], true)
                && ! (PHP_SAPI === 'cli' && app()->environment('testing')));
        $changed = false;
        try {
            if (! $enabled) {
                if ($longLived || $prior === false) {
                    return new JsonResponse(['message' => 'Отправка обращения временно недоступна.'], 503, ['Cache-Control' => 'no-store, private']);
                }
                $changed = @ini_set('zend.exception_ignore_args', '1') !== false;
                if (! $changed || ini_get('zend.exception_ignore_args') !== '1') {
                    return new JsonResponse(['message' => 'Отправка обращения временно недоступна.'], 503, ['Cache-Control' => 'no-store, private']);
                }
            }

            return $next($request);
        } finally {
            try {
                // RequestHandled is dispatched after the middleware pipeline.
                $server = $request->server->all();
                $server['QUERY_STRING'] = '';
                $server['REQUEST_URI'] = '/help/support';
                $request->initialize([], [], $request->attributes->all(), $request->cookies->all(), [], $server, '');
                $request->setJson(new InputBag);
                (new ReflectionProperty(Request::class, 'convertedFiles'))->setValue($request, null);
            } finally {
                if ($changed) {
                    @ini_set('zend.exception_ignore_args', $prior);
                }
            }
        }
    }
}
