<?php

namespace App\Shell\Integrations\Webhooks;

use App\Shell\Integrations\SecretTransport;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/** Block and clear untrusted webhook configuration before routing or logging. */
final class WebhookConfigurationBoundary
{
    public function handle(#[\SensitiveParameter] Request $request, Closure $next): Response
    {
        $path = trim($request->decodedPath(), '/');
        if ($path === 'integrations/api/webhook-settings' && $request->getRealMethod() === 'POST') {
            $payload = ['url' => $request->input('url'), 'events' => $request->input('events'), 'secret' => $request->input('secret')];
            $secure = SecretTransport::allows($request);
            $cookies = $request->cookies->all();
            $server = $request->server->all();
            $server['REQUEST_URI'] = '/integrations/api/webhook-settings';
            $server['QUERY_STRING'] = '';
            unset($server['HTTP_REFERER'], $server['HTTP_X_ORIGINAL_URL'], $server['HTTP_X_REWRITE_URL']);
            $request->initialize([], [], ['webhook_configuration' => $payload, 'webhook_transport_secure' => $secure], $cookies, [], $server, '');
            $request->setJson(new InputBag);
            if (! isset($_SERVER['LARAVEL_OCTANE']) && getenv('LARAVEL_OCTANE') === false
                && ! isset($_SERVER['OCTANE_SERVER']) && getenv('OCTANE_SERVER') === false
                && ! isset($_SERVER['RR_MODE']) && getenv('RR_MODE') === false && ! class_exists('Swoole\\Coroutine', false)) {
                $_GET = $_POST = $_REQUEST = $_FILES = [];
                $_SERVER['QUERY_STRING'] = '';
                $_SERVER['REQUEST_URI'] = '/integrations/api/webhook-settings';
                unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_X_ORIGINAL_URL'], $_SERVER['HTTP_X_REWRITE_URL']);
            }

            return $next($request);
        }
        if (($path !== 'integrations/api/webhooks' && ! str_starts_with($path, 'integrations/api/webhooks/'))
            || (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
                && in_array($request->getRealMethod(), ['GET', 'HEAD', 'OPTIONS'], true))) {
            return $next($request);
        }

        // RequestHandled runs after middleware and may inspect this same Request.
        $server = [
            'REQUEST_METHOD' => $request->getRealMethod(),
            'QUERY_STRING' => '',
            'REQUEST_URI' => '/integrations/api/webhooks',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
        ];
        $request->initialize([], [], [], [], [], $server, '');
        $request->setJson(new InputBag);
        // These process-global variables are safe to clear only in request-per-process runtimes.
        // In coroutine workers, the sanitized Request remains the logging boundary.
        $concurrent = isset($_SERVER['LARAVEL_OCTANE']) || getenv('LARAVEL_OCTANE') !== false
            || isset($_SERVER['OCTANE_SERVER']) || getenv('OCTANE_SERVER') !== false
            || isset($_SERVER['RR_MODE']) || getenv('RR_MODE') !== false || class_exists('Swoole\\Coroutine', false);
        if (! $concurrent) {
            $_GET = $_POST = $_REQUEST = $_FILES = [];
            $_SERVER['QUERY_STRING'] = '';
            $_SERVER['REQUEST_URI'] = '/integrations/api/webhooks';
            unset($_SERVER['HTTP_X_ORIGINAL_URL'], $_SERVER['HTTP_X_REWRITE_URL'], $_SERVER['HTTP_REFERER'], $_SERVER['PATH_INFO'], $_SERVER['ORIG_PATH_INFO'], $_SERVER['REDIRECT_URL'], $_SERVER['REDIRECT_QUERY_STRING']);
        }

        return response()->json(['message' => 'Настройка и отправка вебхуков временно недоступны.'], 503)
            ->header('Cache-Control', 'no-store, private');
    }
}
