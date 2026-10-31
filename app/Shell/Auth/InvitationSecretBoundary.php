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
        if (! $request->isMethod('POST') || $request->decodedPath() !== 'invitations/exchange') {
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
            $epoch = is_array($input) ? ($input['epoch'] ?? null) : null;
            $sequence = is_array($input) ? ($input['sequence'] ?? null) : null;
            $epoch = is_string($epoch) && preg_match('/^[a-f0-9]{64}$/D', $epoch) ? $epoch : null;
            $sequence = is_int($sequence) && $sequence >= 1 && $sequence <= 2147483647 ? $sequence : null;
            unset($raw, $input, $token, $stream);
            $request->attributes->set('invitation.exchange_hash', $hash);
            $request->attributes->set('invitation.document_epoch', $epoch);
            $request->attributes->set('invitation.sequence', $sequence);

            return $next($request);
        } catch (Throwable) {
            // Never propagate an exception whose trace could capture the original body.
            return response()->json(['ok' => false], 404)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }
    }
}
