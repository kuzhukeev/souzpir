<?php

namespace App\Shell\Auth;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Server counterpart to the browser's secure-context requirement for encrypted history. */
final class SecureAccountContext
{
    public const MESSAGE = 'Для этой операции откройте кабинет по защищённому HTTPS-адресу.';

    public static function allows(Request $request): bool
    {
        $nativeTls = $request->server('HTTPS');
        if ($nativeTls && strtolower((string) $nativeTls) !== 'off') {
            return true;
        }

        // Laravel can automatically trust forwarded headers for some Host suffixes.
        // Accept forwarded HTTPS only from explicitly configured proxy addresses.
        $configured = config('trustedproxy.proxies');
        $proxies = is_string($configured) ? array_map('trim', explode(',', $configured)) : $configured;
        if ($request->isSecure() && $request->isFromTrustedProxy() && is_array($proxies) && $proxies !== []
            && ! array_intersect($proxies, ['*', '**', 'REMOTE_ADDR', 'PRIVATE_SUBNETS'])
            && IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), $proxies)) {
            return true;
        }

        // Host is client-controlled. Local HTTP is permitted only for an actual
        // loopback connection in a local/testing environment, never through a proxy.
        return app()->environment('local', 'testing')
            && self::loopback($request->getHost())
            && self::loopback((string) $request->server('REMOTE_ADDR'))
            && self::loopback((string) $request->server('SERVER_ADDR'))
            && ! $request->headers->has('Forwarded')
            && ! $request->headers->has('X-Forwarded-For')
            && ! $request->headers->has('X-Forwarded-Host')
            && ! $request->headers->has('X-Forwarded-Proto');
    }

    public static function validate(Request $request, string $field): void
    {
        if (! self::allows($request)) {
            throw ValidationException::withMessages([$field => self::MESSAGE]);
        }
    }

    public static function assertRead(Request $request): void
    {
        abort_unless(self::allows($request), 403, self::MESSAGE);
    }

    public static function canonicalEmailAllowed(): bool
    {
        $parts = parse_url((string) config('app.url'));
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return ($parts['scheme'] ?? null) === 'https'
            || (app()->environment('local', 'testing') && ($parts['scheme'] ?? null) === 'http' && self::loopback($parts['host']));
    }

    private static function loopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }
}
