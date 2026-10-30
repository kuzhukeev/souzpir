<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects anonymous HTML requests without depending on a named login route', function (): void {
    Route::middleware(['web', 'auth'])->get('/_test/guarded', fn () => response('guarded'));
    $this->get('/_test/guarded')->assertRedirect('/login');
    $this->getJson('/_test/guarded')->assertUnauthorized();
});

it('renders the local Inertia preview with explicit anonymous props', function (): void {
    $this->withoutVite();
    $this->get('/preview')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Foundation/Smoke', false)
        ->where('preview', true)
        ->where('auth.user', null)
        ->where('organization', null)
        ->where('modules.seller', false)
        ->where('modules.vision', false)
        ->where('unread', 0)
        ->has('request_id'));
});

it('blocks preview in production', function (): void {
    $this->app->instance('env', 'production');
    $this->get('/preview')->assertNotFound();
    $this->get('/')->assertNotFound();
});

it('migrates bootstrap tables on the isolated PostgreSQL test database', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    expect(DB::connection()->getDatabaseName())->toMatch('/(?:^|_)test(?:_|$)/');
    expect(Schema::hasTable('users'))->toBeTrue();
    expect(Schema::hasTable('jobs'))->toBeTrue();
    expect(DB::table('users')->count())->toBe(0);
    $user = User::create(['name' => 'Тест', 'email' => 'foundation@example.test', 'password' => 'test-only-password']);
    expect($user->id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i');
    expect(Schema::getColumnType('users', 'id'))->toBe('bpchar');
    expect(Schema::getColumnType('sessions', 'user_id'))->toBe('bpchar');
});

it('isolates storage and all compiled caches within this checkout', function (): void {
    $root = str_replace('\\', '/', base_path('.local/runtime/testing')).'/';
    foreach ([storage_path(), app()->getCachedConfigPath(), app()->getCachedRoutesPath(), app()->getCachedEventsPath(), config('view.compiled')] as $path) {
        expect(str_replace('\\', '/', $path))->toStartWith($root);
    }
    expect(config('queue.default'))->toBe('sync')
        ->and(config('mail.default'))->toBe('log')
        ->and(config('runtime.ai_driver'))->toBe('fake')
        ->and(config('runtime.delivery_driver'))->toBe('fake')
        ->and(config('runtime.features_scheduling_enabled'))->toBeFalse();
});

it('rejects a runtime path outside the checkout', function (): void {
    $previousEnv = $_ENV['APP_STORAGE_PATH'];
    $previousServer = $_SERVER['APP_STORAGE_PATH'];
    try {
        $_ENV['APP_STORAGE_PATH'] = $_SERVER['APP_STORAGE_PATH'] = base_path('../unsafe-storage');
        expect(fn () => prepareRuntime(base_path()))->toThrow(RuntimeException::class, 'inside this checkout');
    } finally {
        $_ENV['APP_STORAGE_PATH'] = $previousEnv;
        $_SERVER['APP_STORAGE_PATH'] = $previousServer;
    }
});

it('rejects a DB_URL which redirects the test configuration to the preview database', function (): void {
    $originalDefault = config('database.default');
    $probe = config('database.connections.'.$originalDefault);
    $probe['database'] = config('runtime.test_database');
    $probe['url'] = 'pgsql://fake:fake@127.0.0.1:65432/'.config('runtime.preview_database');
    config(['database.connections.guard_probe' => $probe, 'database.default' => 'guard_probe']);
    try {
        expect(fn () => (new AppServiceProvider($this->app))->boot())
            ->toThrow(RuntimeException::class, 'isolated PostgreSQL test database');
    } finally {
        DB::purge('guard_probe');
        config(['database.default' => $originalDefault]);
    }
});
