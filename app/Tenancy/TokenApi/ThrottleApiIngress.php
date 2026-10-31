<?php

namespace App\Tenancy\TokenApi;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Bound unauthenticated work before Sanctum without trusting client forwarding headers. */
final class ThrottleApiIngress
{
    private const MAX_ATTEMPTS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $peer = $this->peerKey($request->server->get('REMOTE_ADDR'));
        if ($peer === null) {
            return $this->refuse('Не удалось определить адрес подключения.', 403);
        }

        try {
            $name = config('cache.limiter') ?: config('cache.default');
            $driver = is_string($name) ? config('cache.stores.'.$name.'.driver') : null;
            $shared = in_array($driver, ['database', 'redis', 'memcached'], true)
                || ($driver === 'file' && app()->environment(['local', 'testing']))
                || ($driver === 'array' && app()->environment('testing') && defined('PHPUNIT_COMPOSER_INSTALL'));
            if (! $shared) {
                return $this->refuse('Защита API временно недоступна.', 503);
            }
            $cache = Cache::store($name);
            $limiter = new RateLimiter($cache);
            // A single cache lock serializes the check and increment across workers.
            // The bearer has already been scrubbed by the global request boundary.
            $allowed = $cache->lock('api-ingress-lock:'.$peer, 30)->block(1, function () use ($limiter, $peer): bool {
                $key = 'api-ingress:'.$peer;
                if ($limiter->tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                    return false;
                }
                $limiter->hit($key, 60);

                return true;
            });
        } catch (Throwable) {
            // Do not leak request headers or backend error details into logs or responses.
            return $this->refuse('Защита API временно недоступна.', 503);
        }

        return $allowed ? $next($request) : $this->refuse('Слишком много запросов.', 429)->header('Retry-After', '60');
    }

    private function peerKey(mixed $address): ?string
    {
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return null;
        }
        // An IPv4-mapped IPv6 address and its IPv4 form share one bucket.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packed = substr($packed, 12);
        }

        return hash('sha256', $packed);
    }

    private function refuse(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status)->header('Cache-Control', 'private, no-store');
    }
}
