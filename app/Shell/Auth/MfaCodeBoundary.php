<?php

namespace App\Shell\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Remove OTPs before CSRF, routing, exception handlers and request listeners. */
final class MfaCodeBoundary
{
    public function handle(#[\SensitiveParameter] Request $request, Closure $next): Response
    {
        $path = trim($request->decodedPath(), '/');
        if (! $request->isMethod('POST') || ! in_array($path, ['mfa/challenge', 'profile/security/mfa/recovery'], true)) {
            return $next($request);
        }
        $parsed = null;
        $csrf = null;
        $failed = false;
        try {
            $stream = $request->getContent(true);
            $content = stream_get_contents($stream, 4097);
            $valid = is_string($content) && strlen($content) <= 4096;
            $input = $request->isJson() ? json_decode($valid ? $content : '{}', true) : $request->request->all();
            $input = is_array($input) && $valid ? $input : [];
            $parsed = MfaCode::parse($input['code'] ?? null);
            $csrf = $input['_token'] ?? null;
        } catch (Throwable) {
            $failed = true;
        } finally {
            unset($content, $input, $stream);
            $_POST = [];
            $server = $request->server->all();
            $server['QUERY_STRING'] = '';
            $server['REQUEST_URI'] = '/'.$path;
            $request->initialize([], is_string($csrf) ? ['_token' => $csrf] : [], $request->attributes->all(),
                $request->cookies->all(), $request->files->all(), $server, '{}');
            $request->setJson(new InputBag(is_string($csrf) ? ['_token' => $csrf] : []));
            $request->attributes->set('mfa.safe_code', $parsed);
        }
        if ($failed) {
            return response()->json(['message' => 'Не удалось проверить код.'], 422)->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
