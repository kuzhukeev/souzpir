<?php

namespace App\Shell\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Remove recovery credentials before routing, CSRF and exception reporting. */
final class PasswordResetExchangeBoundary
{
    public function handle(#[\SensitiveParameter] Request $request, Closure $next): Response
    {
        if ($request->getRealMethod() !== 'POST' || trim($request->decodedPath(), '/') !== 'reset-password/exchange') {
            return $next($request);
        }

        $json = $request->isJson();
        $raw = null;
        try {
            $stream = $request->getContent(true);
            $raw = stream_get_contents($stream, 4097);
        } catch (Throwable) {
            // Fail closed after removing any partially read body.
        } finally {
            unset($stream);
            $_POST = [];
            $_REQUEST = [];
            $server = $request->server->all();
            $server['QUERY_STRING'] = '';
            $server['REQUEST_URI'] = '/reset-password/exchange';
            $request->initialize([], [], $request->attributes->all(), $request->cookies->all(), [], $server, '{}');
            $request->setJson(new InputBag);
        }

        try {
            if (! $json || ! is_string($raw) || strlen($raw) > 4096) {
                return $this->rejected();
            }
            $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            $token = is_array($input) ? ($input['token'] ?? null) : null;
            $email = is_array($input) ? ($input['email'] ?? null) : null;
            if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
                || ! is_string($email) || strlen($email) > 254) {
                return $this->rejected();
            }
            $email = mb_strtolower(trim($email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return $this->rejected();
            }
            $request->attributes->set('password.reset_token_hash', hash('sha256', $token));
            $request->attributes->set('password.reset_email_encrypted', Crypt::encryptString($email));
        } catch (Throwable) {
            return $this->rejected();
        } finally {
            unset($raw, $input, $token, $email);
        }

        return $next($request);
    }

    private function rejected(): Response
    {
        return response()->json(['ok' => false], 404)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
