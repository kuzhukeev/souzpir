<?php

namespace App\Shell\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Remove the bearer from the Request before CSRF, throttling and exception reporting. */
final class InvitationSecretBoundary
{
    public function handle(#[\SensitiveParameter] Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || $request->path() !== 'invitations/exchange') {
            return $next($request);
        }

        try {
            // This global boundary runs before Laravel's ordinary POST-size check.
            // Read only a small bounded JSON body, then discard it before downstream code.
            $stream = $request->getContent(true);
            $raw = stream_get_contents($stream, 4097);
            $request->initialize($request->query->all(), [], $request->attributes->all(),
                $request->cookies->all(), $request->files->all(), $request->server->all(), '{}');
            $request->setJson(new InputBag([]));
            if (! $request->isJson() || ! is_string($raw) || strlen($raw) > 4096) {
                return response()->json(['ok' => false], 404)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
            }
            $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            $token = is_array($input) ? ($input['token'] ?? null) : null;
            $hash = is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token) ? hash('sha256', $token) : null;
            unset($raw, $input, $token, $stream);
            $request->attributes->set('invitation.exchange_hash', $hash);

            return $next($request);
        } catch (Throwable) {
            // Never propagate an exception whose trace could capture the original body.
            return response()->json(['ok' => false], 404)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }
    }
}
