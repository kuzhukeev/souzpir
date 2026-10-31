<?php

namespace App\Tenancy\TokenApi;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/** Replace the bearer with its SHA-256 before framework auth or exception handling. */
final class BearerBoundary
{
    public function handle(#[\SensitiveParameter] Request $request, Closure $next): mixed
    {
        $path = trim(rawurldecode($request->getPathInfo()), '/');
        if ($path !== 'api/v1' && ! str_starts_with($path, 'api/v1/')) {
            return $next($request);
        }
        $header = $request->headers->get('Authorization');
        $request->headers->remove('Authorization');
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'PHP_AUTH_DIGEST'] as $key) {
            $request->server->remove($key);
            // Framework and third-party exception handlers may inspect PHP's
            // original superglobal instead of Symfony's copied server bag.
            unset($_SERVER[$key]);
        }
        $request->headers->set('Accept', 'application/json');
        if (is_string($header) && $header !== '' && ! $this->secureTransport($request)) {
            unset($header);

            return response()->json(['message' => 'Для API требуется защищённое соединение.'], 403)
                ->header('Cache-Control', 'private, no-store');
        }
        if (is_string($header) && preg_match('/^Bearer ([1-9][0-9]{0,18})\|([A-Za-z0-9]{64})$/D', $header, $matches)
            && (strlen($matches[1]) < 19 || strcmp($matches[1], (string) PHP_INT_MAX) <= 0)) {
            $request->headers->set('Authorization', 'Bearer '.$matches[1].'|'.hash('sha256', $matches[2]));
            $request->attributes->set('token_api.boundary', true);
        }
        unset($header, $matches);
        // Laravel RequestGuard caches its principal. Every API request must re-authenticate.
        $auth = app('auth');
        $oldGuard = $auth->getDefaultDriver();
        $auth->forgetGuards();
        try {
            return $next($request);
        } finally {
            $auth->forgetGuards();
            $auth->shouldUse($oldGuard);
        }
    }

    private function secureTransport(Request $request): bool
    {
        // A client-controlled Host can trigger Laravel's automatic proxy trust.
        // Native TLS is server-provided; forwarded HTTPS is verified against
        // the current connection's peer and explicit proxy config, before TrustProxies runs.
        $nativeTls = $request->server('HTTPS');
        if (is_string($nativeTls) && in_array(strtolower(trim($nativeTls)), ['on', '1'], true)) {
            return true;
        }
        $configured = config('trustedproxy.proxies');
        $proxies = is_string($configured) ? explode(',', $configured) : $configured;
        $forwarded = $request->headers->all('X-Forwarded-Proto');
        try {
            if (is_array($proxies) && $proxies !== [] && $this->explicitNetworks($proxies)
                && ! $request->headers->has('Forwarded') && count($forwarded) === 1
                && is_string($forwarded[0]) && strtolower(trim($forwarded[0])) === 'https'
                && IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), array_map('trim', $proxies))) {
                return true;
            }
        } catch (Throwable) {
            return false;
        }
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }
        foreach (['Forwarded', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Real-IP', 'CF-Connecting-IP'] as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        return $this->loopback($request->server->get('REMOTE_ADDR'))
            && $this->loopback($request->server->get('SERVER_ADDR'));
    }

    /** Reject wildcard, invalid, or universal CIDR entries before trusting any proxy headers. */
    private function explicitNetworks(array $proxies): bool
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

    private function loopback(mixed $address): bool
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
