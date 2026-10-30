<?php

namespace Tests\Feature\Smoke;

use App\Http\Middleware\EnsureLocalPreview;
use App\Providers\Features\FeatureRegistry;
use App\Providers\Features\FeatureSeedCommand;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Smoke\Fixtures\CountingSchedule;
use Tests\Feature\Smoke\Fixtures\CountingSeeder;
use Tests\Feature\Smoke\Fixtures\FixtureProps;
use Tests\Feature\Smoke\Fixtures\RegistryApplication;
use Tests\Feature\Smoke\Fixtures\RegistryController;

class FeatureRegistryTest extends TestCase
{
    private RegistryApplication $fixtureApp;

    private string $fixtureRoot;

    private Container $previousContainer;

    private mixed $previousFacade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->fixtureRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'souzpir-feature-registry-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureRoot, 0700, true);
        $this->fixtureApp = new RegistryApplication($this->fixtureRoot);
        $this->fixtureApp->instance('env', 'testing');
        $this->fixtureApp->instance('config', new Repository(['runtime' => ['features_scheduling_enabled' => false]]));
        $this->fixtureApp['config']->set('cache', ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]);
        $this->fixtureApp->register(CacheServiceProvider::class);
        $this->fixtureApp->instance('request', Request::create('/'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->fixtureApp);
        CountingSeeder::$runs = CountingSchedule::$registrations = 0;
        FixtureProps::$evaluations = 0;
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        $resolved = realpath($this->fixtureRoot);
        $allowed = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'souzpir-feature-registry-';
        self::assertNotFalse($resolved);
        self::assertStringStartsWith(strtolower($allowed), strtolower($resolved));
        (new Filesystem)->deleteDirectory($resolved);
        parent::tearDown();
    }

    public function test_discovers_sorted_config_translations_bootstrap_and_feature_migrations(): void
    {
        $this->write('config/features/E2-01.php', "<?php return ['defaults' => ['value' => 'second']];");
        $this->write('config/features/E1-01.php', "<?php return ['defaults' => ['value' => 'first']];");
        $this->write('database/migrations/bootstrap/2026_01_01_000000_create_users.php', '<?php return null;');
        $this->write('database/migrations/E1-01/2026_01_02_000000_e1_01_fixture.php', '<?php return null;');
        $this->write('lang/ru/Shell/Auth/login.php', "<?php return ['title' => 'Вход', 'field' => ['email' => 'Почта']];");

        $registry = new FeatureRegistry($this->fixtureApp);
        $registry->register();

        self::assertSame(['E1-01', 'E2-01'], array_keys($this->fixtureApp['config']->get('features')));
        self::assertSame('first', $this->fixtureApp['config']->get('features.E1-01.defaults.value'));
        self::assertCount(2, $registry->migrationPaths());
        self::assertSame(['Shell/Auth/login.title' => 'Вход', 'Shell/Auth/login.field.email' => 'Почта'], $registry->translations());
    }

    public function test_cached_configuration_is_used_without_requiring_source_files(): void
    {
        $this->write('config/features/E1-01.php', "<?php throw new LogicException('Should not read source with config cache');");
        $this->fixtureApp->fixtureConfigCached = true;
        $this->fixtureApp['config']->set('features', ['E1-01' => ['defaults' => ['value' => 'cached']]]);
        (new FeatureRegistry($this->fixtureApp))->register();
        self::assertSame('cached', $this->fixtureApp['config']->get('features.E1-01.defaults.value'));
    }

    public function test_scoped_binding_middleware_alias_and_lazy_shared_props(): void
    {
        $this->write('config/features/E1-01.php', '<?php return '.var_export([
            'scoped_bindings' => [RegistryController::class => RegistryController::class],
            'middleware_aliases' => ['fixture.tenant' => EnsureLocalPreview::class],
            'shared_props' => [FixtureProps::class],
        ], true).';');
        $registry = new FeatureRegistry($this->fixtureApp);
        $registry->register();
        $first = $this->fixtureApp->make(RegistryController::class);
        self::assertSame($first, $this->fixtureApp->make(RegistryController::class));
        $this->fixtureApp->forgetScopedInstances();
        self::assertNotSame($first, $this->fixtureApp->make(RegistryController::class));
        self::assertSame(EnsureLocalPreview::class, $this->fixtureApp['router']->getMiddleware()['fixture.tenant']);
        $request = Request::create('/');
        $props = $registry->sharedProps($request);
        self::assertSame(0, FixtureProps::$evaluations);
        $request->attributes->set('tenant', 'resolved-after-share');
        self::assertSame('resolved-after-share', $props['fixture_organization']());
        self::assertSame(1, FixtureProps::$evaluations);
    }

    public function test_rejects_duplicate_shared_props_at_request_time(): void
    {
        foreach (['E1-01', 'E2-01'] as $id) {
            $this->write("config/features/{$id}.php", '<?php return '.var_export(['shared_props' => [FixtureProps::class]], true).';');
        }
        $registry = new FeatureRegistry($this->fixtureApp);
        $registry->register();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('shared prop');
        $registry->sharedProps(Request::create('/'));
    }

    public function test_preview_route_gets_name_lookup_and_no_future_tenant_alias(): void
    {
        $this->write('routes/features/P-01.php', $this->routeFile('preview', '/feature', 'fixture.preview'));
        $registry = new FeatureRegistry($this->fixtureApp);
        $registry->boot();
        $this->fixtureApp->boot();
        $route = $this->fixtureApp->make(Router::class)->getRoutes()->getByName('fixture.preview');
        self::assertNotNull($route);
        self::assertSame('preview/feature', $route->uri());
        self::assertSame(['web', EnsureLocalPreview::class], $route->gatherMiddleware());
    }

    public function test_cached_routes_are_not_reloaded_or_replaced(): void
    {
        $router = $this->fixtureApp->make(Router::class);
        $router->get('/cached', RegistryController::class)->name('fixture.cached');
        $router->getRoutes()->refreshNameLookups();
        $router->setCompiledRoutes($router->getRoutes()->compile());
        $collection = $router->getRoutes();
        $this->write('routes/features/E1-01.php', "<?php throw new LogicException('Cached route source executed');");
        $this->fixtureApp->fixtureRoutesCached = true;
        (new FeatureRegistry($this->fixtureApp))->boot();
        $this->fixtureApp->boot();
        self::assertSame($collection, $router->getRoutes());
        self::assertSame('cached', $router->getRoutes()->getByName('fixture.cached')->uri());
    }

    #[DataProvider('conflictingSections')]
    public function test_rejects_duplicate_binding_extension_and_permission_keys(string $section, array $value): void
    {
        foreach (['E1-01', 'E2-01'] as $id) {
            $this->write("config/features/{$id}.php", '<?php return '.var_export([$section => $value], true).';');
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate');
        (new FeatureRegistry($this->fixtureApp))->register();
    }

    public static function conflictingSections(): array
    {
        return [
            'bindings' => ['bindings', [RegistryController::class => RegistryController::class]],
            'extensions' => ['extensions', ['example' => RegistryController::class]],
            'permissions' => ['permissions', ['example.read' => ['owner']]],
            'scoped bindings' => ['scoped_bindings', [RegistryController::class => RegistryController::class]],
            'middleware aliases' => ['middleware_aliases', ['fixture.tenant' => EnsureLocalPreview::class]],
        ];
    }

    public function test_rejects_duplicate_route_names_even_when_same_uri_would_overwrite(): void
    {
        $routes = $this->routeFile('preview', '/same', 'fixture.duplicate');
        $this->write('routes/features/E1-01.php', $routes);
        $this->write('routes/features/E2-01.php', $routes);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('route name');
        (new FeatureRegistry($this->fixtureApp))->boot();
        $this->fixtureApp->boot();
    }

    public function test_rejects_duplicate_migration_basenames(): void
    {
        foreach (['bootstrap', 'E1-01'] as $directory) {
            $this->write("database/migrations/{$directory}/2026_01_01_000000_same.php", '<?php return null;');
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('migration basename');
        (new FeatureRegistry($this->fixtureApp))->register();
    }

    public function test_rejects_non_cacheable_configuration(): void
    {
        $this->write('config/features/E1-01.php', "<?php return ['defaults' => ['callback' => static fn () => true]];");
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Non-cacheable');
        (new FeatureRegistry($this->fixtureApp))->register();
    }

    public function test_rejects_invalid_feature_id(): void
    {
        $this->write('config/features/not-a-task.php', '<?php return [];');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid feature ID');
        (new FeatureRegistry($this->fixtureApp))->register();
    }

    public function test_rejects_ambiguous_nested_translation_keys(): void
    {
        $this->write('lang/ru/Shell/login.php', "<?php return ['field.email' => 'First', 'field' => ['email' => 'Second']];");
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('translation key');
        (new FeatureRegistry($this->fixtureApp))->register();
    }

    public function test_rejects_duplicate_names_within_the_same_route_callback(): void
    {
        $file = $this->routeFile('preview', '/same', 'fixture.same');
        $route = '\Illuminate\Support\Facades\Route::get("/same", '.var_export(RegistryController::class, true).')->name("fixture.same");';
        $this->write('routes/features/E1-01.php', str_replace(' }];', $route.' }];', $file));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('route name');
        (new FeatureRegistry($this->fixtureApp))->boot();
        $this->fixtureApp->boot();
    }

    public function test_rejects_closure_feature_handlers_before_route_cache(): void
    {
        $this->write('routes/features/E1-01.php', '<?php return ["preview" => static function (): void { \Illuminate\Support\Facades\Route::get("/invalid", static fn () => "invalid"); }];');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be controller actions');
        (new FeatureRegistry($this->fixtureApp))->boot();
        $this->fixtureApp->boot();
    }

    public function test_webhook_group_uses_registered_named_limiter(): void
    {
        $this->write('routes/features/E1-01.php', $this->routeFile('webhook', '/fixture', 'fixture.webhook'));
        $router = $this->fixtureApp->make(Router::class);
        $router->middlewareGroup('api', []);
        $router->aliasMiddleware('throttle', ThrottleRequests::class);
        $this->fixtureApp->make(RateLimiter::class)->for('webhook', static fn () => Limit::perMinute(1)->by('fixture'));
        (new FeatureRegistry($this->fixtureApp))->boot();
        $this->fixtureApp->boot();
        self::assertSame(200, $router->dispatch(Request::create('/webhooks/fixture'))->getStatusCode());
        $this->expectException(ThrottleRequestsException::class);
        $router->dispatch(Request::create('/webhooks/fixture'));
    }

    public function test_schedule_and_seeders_do_not_run_implicitly_and_scheduling_requires_flag(): void
    {
        $this->write('config/features/E1-01.php', '<?php return '.var_export([
            'schedules' => [CountingSchedule::class], 'seeders' => [CountingSeeder::class],
        ], true).';');
        $registry = new FeatureRegistry($this->fixtureApp);
        $registry->boot();
        $this->fixtureApp->make(Schedule::class);
        self::assertSame(0, CountingSchedule::$registrations);
        self::assertSame(0, CountingSeeder::$runs);
        self::assertSame([CountingSeeder::class], $registry->seeders('E1-01'));
    }

    public function test_explicit_scheduling_flag_registers_contributor_without_running_it(): void
    {
        $this->write('config/features/E1-01.php', '<?php return '.var_export(['schedules' => [CountingSchedule::class]], true).';');
        $this->fixtureApp['config']->set('runtime.features_scheduling_enabled', true);
        (new FeatureRegistry($this->fixtureApp))->boot();
        $schedule = $this->fixtureApp->make(Schedule::class);
        self::assertSame(1, CountingSchedule::$registrations);
        self::assertCount(1, $schedule->events());
    }

    public function test_seed_command_requires_selection_and_rejects_production(): void
    {
        $this->write('config/features/E1-01.php', '<?php return '.var_export(['seeders' => [CountingSeeder::class]], true).';');
        (new FeatureRegistry($this->fixtureApp))->register();
        $command = new FeatureSeedCommand;
        $command->setLaravel($this->fixtureApp);
        self::assertSame(1, $command->run(new ArrayInput([]), new BufferedOutput));
        self::assertSame(0, CountingSeeder::$runs);
        self::assertSame(0, $command->run(new ArrayInput(['feature' => 'E1-01']), new BufferedOutput));
        self::assertSame(1, CountingSeeder::$runs);
        $this->fixtureApp->instance('env', 'production');
        self::assertSame(1, $command->run(new ArrayInput(['--all' => true]), new BufferedOutput));
        self::assertSame(1, CountingSeeder::$runs);
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->fixtureRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $content);
    }

    private function routeFile(string $group, string $uri, string $name): string
    {
        return '<?php return ['.var_export($group, true).' => static function (): void {'
            .'\Illuminate\Support\Facades\Route::get('.var_export($uri, true).', '.var_export(RegistryController::class, true).')->name('.var_export($name, true).'); }];';
    }
}
