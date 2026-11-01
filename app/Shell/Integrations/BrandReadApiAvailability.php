<?php

namespace App\Shell\Integrations;

use App\Tenancy\TokenApi\BearerBoundary;
use App\Tenancy\TokenApi\BrandAccessToken;
use App\Tenancy\TokenApi\BrandReadController;
use App\Tenancy\TokenApi\RequireAbility;
use App\Tenancy\TokenApi\ResolveTokenTenant;
use App\Tenancy\TokenApi\ThrottleApiIngress;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Throwable;

/** Conservative configuration check, not a live health check or a grant to a key. */
final class BrandReadApiAvailability
{
    public function configured(Request $request): bool
    {
        try {
            $route = Route::getRoutes()->getByName('api.v1.brand.show');
            if (! $route || $route->uri() !== 'api/v1/brand' || ! in_array('GET', $route->methods(), true)
                || $route->getActionName() !== BrandReadController::class
                || array_diff(['api', 'api.ingress', 'auth:sanctum', 'tenant.token', 'throttle:api', 'token.ability:read'], $route->gatherMiddleware())
                || ! app(Kernel::class)->hasMiddleware(BearerBoundary::class)
                || ! SecretTransport::allows($request)) {
                return false;
            }
            $aliases = app('router')->getMiddleware();
            if (($aliases['api.ingress'] ?? null) !== ThrottleApiIngress::class
                || ($aliases['tenant.token'] ?? null) !== ResolveTokenTenant::class
                || ($aliases['token.ability'] ?? null) !== RequireAbility::class
                || Sanctum::$personalAccessTokenModel !== BrandAccessToken::class
                || RateLimiter::limiter('api') === null) {
                return false;
            }
            Auth::guard('sanctum');
            $store = config('cache.limiter') ?: config('cache.default');
            $driver = is_string($store) ? config('cache.stores.'.$store.'.driver') : null;
            $supported = in_array($driver, ['database', 'redis', 'memcached'], true)
                || ($driver === 'file' && app()->environment(['local', 'testing']))
                || ($driver === 'array' && app()->environment('testing') && defined('PHPUNIT_COMPOSER_INSTALL'));
            if (! $supported) {
                return false;
            }
            if ($driver === 'file' && (! $this->writableDirectory(config('cache.stores.'.$store.'.path'))
                || ! $this->writableDirectory(config('cache.stores.'.$store.'.lock_path') ?: config('cache.stores.'.$store.'.path')))) {
                return false;
            }
            // A read-only probe detects an unreachable limiter backend; it does not consume a rate limit.
            Cache::store($store)->get('integrations:brand-read-api:availability-probe');
            if ($driver === 'database') {
                $connection = config('cache.stores.'.$store.'.connection') ?: config('database.default');
                $lockConnection = config('cache.stores.'.$store.'.lock_connection') ?: $connection;
                $table = config('cache.stores.'.$store.'.table', 'cache');
                $lockTable = config('cache.stores.'.$store.'.lock_table') ?: 'cache_locks';
                if (! is_string($connection) || ! is_string($lockConnection) || ! is_string($table) || ! is_string($lockTable)
                    || ! Schema::connection($connection)->hasTable($table) || ! Schema::connection($lockConnection)->hasTable($lockTable)) {
                    return false;
                }
            }

            return Schema::hasColumns('personal_access_tokens', [
                'id', 'tokenable_type', 'tokenable_id', 'organization_id', 'brand_id', 'token', 'abilities', 'last_used_at', 'expires_at',
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    private function writableDirectory(mixed $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }
        while (! is_dir($path)) {
            $parent = dirname($path);
            if ($parent === $path || file_exists($path)) {
                return false;
            }
            $path = $parent;
        }

        return is_writable($path);
    }
}
