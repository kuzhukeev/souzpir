<?php

namespace App\Shell\Integrations;

use App\Privacy\VerifiedHttps;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/** Only native TLS, an explicit trusted TLS proxy, or direct local loopback can carry secrets. */
final class SecretTransport
{
    public const MESSAGE = 'Для создания и просмотра секретов откройте кабинет по HTTPS или через localhost на этом компьютере.';

    public static function allows(Request $request): bool
    {
        // Do not use isSecure(): Laravel can infer proxy trust from an attacker-controlled Host.
        $nativeTls = $request->server('HTTPS');
        if (! VerifiedHttps::hasForwardingHeaders($request)
            && is_string($nativeTls) && in_array(strtolower(trim($nativeTls)), ['on', '1'], true)) {
            return true;
        }

        $configured = config('trustedproxy.proxies');
        $proxies = is_string($configured) ? explode(',', $configured) : $configured;
        $forwarded = $request->headers->all('X-Forwarded-Proto');
        try {
            if (is_array($proxies) && $proxies !== [] && self::explicitNetworks($proxies)
                && ! $request->headers->has('Forwarded') && count($forwarded) === 1
                && is_string($forwarded[0]) && strtolower(trim($forwarded[0])) === 'https'
                && IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), array_map('trim', $proxies))) {
                return true;
            }
        } catch (Throwable) {
            return false;
        }

        if (! app()->environment('local', 'testing')) {
            return false;
        }
        if (VerifiedHttps::hasForwardingHeaders($request)) {
            return false;
        }

        return self::loopback($request->server->get('REMOTE_ADDR'))
            && self::loopback($request->server->get('SERVER_ADDR'));
    }

    public static function assertAllowed(Request $request): void
    {
        abort_unless(self::allows($request), 403, self::MESSAGE);
    }

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
                $maximum = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
                if (! ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > $maximum) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function loopback(mixed $address): bool
    {
        if (! is_string($address)) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($address, '127.');
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && inet_pton($address) === inet_pton('::1');
    }
}
