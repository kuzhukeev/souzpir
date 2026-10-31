<?php

namespace App\Providers;

use App\Providers\Features\FeatureRegistry;
use App\Tenancy\TokenApi\BrandAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureRegistry::class, fn ($app) => new FeatureRegistry($app));
        $this->app->make(FeatureRegistry::class)->register();
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(BrandAccessToken::class);
        if ($this->app->environment('testing')) {
            // DatabaseManager resolves DB_URL overrides before any connection is opened.
            $connection = DB::connection();
            $database = $connection->getDatabaseName();
            if ($connection->getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)test(?:_|$)/', $database) || $database !== config('runtime.test_database') || $database === config('runtime.preview_database')) {
                throw new \RuntimeException('Tests require an isolated PostgreSQL test database, never the preview database.');
            }
            Http::preventStrayRequests();
        }
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        $this->app->make(FeatureRegistry::class)->boot();
    }
}
