<?php

namespace App\Privacy;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/** Verify transport without trusting a client-controlled Host or stale global proxy state. */
final class VerifiedHttps
{
    public static function allows(Request $request): bool
    {
        $native = $request->server('HTTPS');
        if (! self::hasForwardingHeaders($request)
            && is_string($native) && in_array(strtolower(trim($native)), ['on', '1'], true)) {
            return true;
        }

        $value = config('trustedproxy.proxies');
        $proxies = is_string($value) ? array_map('trim', explode(',', $value)) : $value;
        $forwarded = $request->headers->all('X-Forwarded-Proto');
        try {
            return is_array($proxies) && $proxies !== [] && self::explicitNetworks($proxies)
                && ! $request->headers->has('Forwarded') && count($forwarded) === 1
                && is_string($forwarded[0]) && strtolower(trim($forwarded[0])) === 'https'
                && IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), array_map('trim', $proxies));
        } catch (Throwable) {
            return false;
        }
    }

    /** Both ends must be genuinely loopback and no forwarding proxy may be involved. */
    public static function directLoopback(Request $request): bool
    {
        return ! self::hasForwardingHeaders($request)
            && self::loopback($request->server('REMOTE_ADDR'))
            && self::loopback($request->server('SERVER_ADDR'));
    }

    /** Forwarded protocol indicators require an explicitly trusted peer, even if HTTPS is set. */
    public static function hasForwardingHeaders(Request $request): bool
    {
        foreach (array_keys($request->headers->all()) as $name) {
            $name = strtolower($name);
            if ($name === 'forwarded' || str_starts_with($name, 'x-forwarded-')
                || $name === 'x-real-ip' || $name === 'cf-connecting-ip') {
                return true;
            }
        }

        // Framework middleware can remove a header while the original server
        // variables still show that a proxy-style value arrived.
        foreach (array_keys($request->server->all()) as $name) {
            $name = strtoupper($name);
            if (str_starts_with($name, 'REDIRECT_')) {
                $name = substr($name, 9);
            }
            if ($name === 'HTTP_FORWARDED' || str_starts_with($name, 'HTTP_X_FORWARDED_')
                || $name === 'HTTP_X_REAL_IP' || $name === 'HTTP_CF_CONNECTING_IP') {
                return true;
            }
        }

        return false;
    }

    private static function loopback(mixed $ip): bool
    {
        if (! is_string($ip)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '127.');
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && inet_pton($ip) === inet_pton('::1');
    }

    /** Explicit IPs and valid, non-universal CIDRs only. */
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
