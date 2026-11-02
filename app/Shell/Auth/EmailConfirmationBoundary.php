<?php

namespace App\Shell\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/** Keep the POST bearer out of RequestHandled and application logs. */
final class EmailConfirmationBoundary
{
    public function handle(#[\SensitiveParameter] Request $request, Closure $next): Response
    {
        $token = $request->isJson() ? $request->json('token') : $request->request->get('token');
        $attributes = $request->attributes->all();
        $attributes['profile.email.token'] = $token;
        $server = $request->server->all();
        $server['QUERY_STRING'] = $request->getQueryString() ?? '';
        $request->initialize([], [], $attributes, $request->cookies->all(), [], $server, '');
        $request->setJson(new InputBag);
        if (! isset($_SERVER['LARAVEL_OCTANE']) && getenv('LARAVEL_OCTANE') === false
            && ! isset($_SERVER['OCTANE_SERVER']) && getenv('OCTANE_SERVER') === false
            && ! isset($_SERVER['RR_MODE']) && getenv('RR_MODE') === false && ! class_exists('Swoole\\Coroutine', false)) {
            $_POST = [];
            unset($_REQUEST['token']);
        }

        return $next($request);
    }
}
