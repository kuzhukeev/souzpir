<?php

namespace App\Shell\Auth;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/** Server counterpart to the browser's secure-context requirement for encrypted history. */
final class SecureAccountContext
{
    public const MESSAGE = 'Для этой операции откройте кабинет по защищённому HTTPS-адресу.';

    public static function allows(Request $request): bool
    {
        $nativeTls = $request->server('HTTPS');
        if (is_string($nativeTls) && in_array(strtolower(trim($nativeTls)), ['on', '1'], true)) {
            return true;
        }

        // Laravel can automatically trust forwarded headers for some Host suffixes.
        // Accept forwarded HTTPS only from explicitly configured proxy addresses.
        $configured = config('trustedproxy.proxies');
        $proxies = is_string($configured) ? array_map('trim', explode(',', $configured)) : $configured;
        $forwarded = $request->headers->all('X-Forwarded-Proto');
        try {
            // Use only the explicitly configured peer and an unambiguous protocol;
            // Symfony's static trust and Forge/Vapor Host shortcuts are not proof.
            if (is_array($proxies) && $proxies !== [] && self::explicitNetworks($proxies)
                && ! $request->headers->has('Forwarded') && count($forwarded) === 1
                && is_string($forwarded[0]) && strtolower(trim($forwarded[0])) === 'https'
                && IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), array_map('trim', $proxies))) {
                return true;
            }
        } catch (Throwable) {
            return false;
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

    /** Accept explicit IPs and non-universal valid CIDRs only. */
    private static function explicitNetworks(array $proxies): bool
    {
        foreach ($proxies as $proxy) {
            if (! is_string($proxy)) {
                return false;
            }
            $parts = explode('/', trim($proxy));
            if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
                return false;
            }
            if (count($parts) === 2) {
                $max = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
                if (! ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > $max) {
                    return false;
                }
            }
        }

        return true;
    }
}
