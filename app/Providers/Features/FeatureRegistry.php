<?php

namespace App\Providers\Features;

use App\Http\Middleware\EnsureLocalPreview;
use Closure;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FeatureRegistry
{
    private const ID_PATTERN = '/^[A-Z][A-Z0-9]*-[0-9]{2}[A-Z]?$/D';

    private const SECTIONS = ['bindings', 'scoped_bindings', 'middleware_aliases', 'shared_props', 'extensions', 'permissions', 'policies', 'listeners', 'commands', 'schedules', 'seeders', 'defaults'];

    private bool $registered = false;

    private bool $booted = false;

    private array $features = [];

    private array $extensions = [];

    private array $permissions = [];

    private array $policies = [];

    private array $translationLines = [];

    private array $migrationPaths = [];

    public function __construct(private readonly Application $app) {}

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->features = $this->configuration();
        $bindingOwners = [];

        foreach ($this->features as $id => $feature) {
            $this->assertId($id);
            $this->assertSerializable($feature, "Feature {$id}");

            if (! is_array($feature) || array_diff(array_keys($feature), self::SECTIONS)) {
                throw new LogicException("Invalid feature configuration: {$id}");
            }

            foreach (self::SECTIONS as $section) {
                if (isset($feature[$section]) && ! is_array($feature[$section])) {
                    throw new LogicException("Feature {$id}.{$section} must be an array.");
                }
            }

            foreach (['bindings', 'scoped_bindings'] as $bindingType) {
                foreach ($feature[$bindingType] ?? [] as $abstract => $concrete) {
                    if (isset($bindingOwners[$abstract]) || $this->app->bound($abstract)) {
                        throw new LogicException("Duplicate feature binding: {$abstract}");
                    }
                    if (! is_string($abstract) || (! interface_exists($abstract) && ! class_exists($abstract))) {
                        throw new LogicException("Unknown feature contract: {$abstract}");
                    }
                    $this->assertClass($concrete, $abstract);
                    $bindingOwners[$abstract] = $id;
                    $bindingType === 'scoped_bindings'
                        ? $this->app->scoped($abstract, $concrete)
                        : $this->app->bind($abstract, $concrete);
                }
            }

            foreach ($feature['middleware_aliases'] ?? [] as $alias => $class) {
                $router = $this->app->make('router');
                $this->assertNewKey($router->getMiddleware(), $alias, 'middleware alias');
                $this->assertClass($class);
                $router->aliasMiddleware($alias, $class);
            }

            foreach ($feature['shared_props'] ?? [] as $class) {
                $this->assertClass($class, SharedPropsContributor::class);
            }

            foreach ($feature['extensions'] ?? [] as $key => $class) {
                $this->assertNewKey($this->extensions, $key, 'extension');
                $this->assertClass($class);
                $this->extensions[$key] = $class;
            }

            foreach ($feature['permissions'] ?? [] as $key => $definition) {
                $this->assertNewKey($this->permissions, $key, 'permission');
                $this->assertClass($definition, PermissionEvaluator::class);
                $this->permissions[$key] = $definition;
            }

            foreach ($feature['policies'] ?? [] as $model => $policy) {
                $this->assertNewKey($this->policies, $model, 'policy');
                $this->assertClass($model);
                $this->assertClass($policy);
                $this->policies[$model] = $policy;
            }

            foreach ($feature['commands'] ?? [] as $class) {
                $this->assertClass($class, Command::class);
            }
            foreach ($feature['seeders'] ?? [] as $class) {
                $this->assertClass($class, Seeder::class);
            }
            foreach ($feature['schedules'] ?? [] as $class) {
                $this->assertClass($class, ScheduleContributor::class);
            }
        }

        $this->app->make('config')->set('features', $this->features);
        $this->migrationPaths = $this->discoverMigrations();
        $this->translationLines = $this->discoverTranslations();
        $this->app->instance(self::class, $this);
        $this->registered = true;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->register();
        $this->booted = true;

        if ($this->permissions || $this->policies) {
            $gate = $this->app->make(Gate::class);
            foreach ($this->permissions as $ability => $evaluator) {
                $gate->define($ability, fn (Authenticatable $user, ...$arguments): bool => $this->app->make($evaluator)->allows($user, $ability, ...$arguments));
            }
            foreach ($this->policies as $model => $policy) {
                $gate->policy($model, $policy);
            }
        }

        $this->afterResolving('migrator', function (Migrator $migrator): void {
            foreach ($this->migrationPaths as $path) {
                $migrator->path($path);
            }
        });

        foreach ($this->features as $feature) {
            foreach ($feature['listeners'] ?? [] as $event => $listeners) {
                if (! is_string($event) || ! is_array($listeners)) {
                    throw new LogicException('Feature listeners must map event names to class lists.');
                }
                foreach ($listeners as $listener) {
                    $this->assertClass($listener);
                    $this->app->make('events')->listen($event, $listener);
                }
            }
        }

        $commands = [FeatureSeedCommand::class];
        foreach ($this->features as $feature) {
            $commands = array_merge($commands, $feature['commands'] ?? []);
        }
        Artisan::starting(static fn (Artisan $artisan) => $artisan->resolveCommands(array_unique($commands)));

        $this->afterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! $this->app->make('config')->get('runtime.features_scheduling_enabled', false)) {
                return;
            }
            foreach ($this->features as $feature) {
                foreach ($feature['schedules'] ?? [] as $class) {
                    $this->app->make($class)->register($schedule);
                }
            }
        });

        $this->app->booted(function (): void {
            if ($this->app->routesAreCached()) {
                // Laravel's cached collection already contains the feature routes.
                // Requiring route files here would overwrite its compiled lookups.
                return;
            }
            $this->loadRoutes();
        });
    }

    public function extensions(): array
    {
        return $this->extensions;
    }

    public function sharedProps(Request $request): array
    {
        $props = [];
        foreach ($this->features as $feature) {
            foreach ($feature['shared_props'] ?? [] as $class) {
                foreach ($this->app->make($class)->share($request) as $key => $value) {
                    $this->assertNewKey($props, $key, 'shared prop');
                    // Inertia resolves closures after the route's tenant middleware.
                    $props[$key] = $value;
                }
            }
        }

        return $props;
    }

    public function permissions(): array
    {
        return $this->permissions;
    }

    public function translations(): array
    {
        return $this->translationLines;
    }

    public function migrationPaths(): array
    {
        return $this->migrationPaths;
    }

    public function seeders(?string $feature = null): array
    {
        if ($feature !== null && ! array_key_exists($feature, $this->features)) {
            throw new LogicException("Unknown feature: {$feature}");
        }

        $selected = $feature === null ? $this->features : [$this->features[$feature]];

        return array_values(array_unique(array_merge([], ...array_map(
            static fn (array $config): array => $config['seeders'] ?? [], $selected,
        ))));
    }

    private function configuration(): array
    {
        if ($this->app->configurationIsCached()) {
            $features = $this->app->make('config')->get('features', []);
            if (! is_array($features)) {
                throw new LogicException('Cached features configuration must be an array.');
            }
            ksort($features, SORT_STRING);

            return $features;
        }

        $features = [];
        foreach ($this->files($this->app->configPath('features').'/*.php') as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            $this->assertId($id);
            $features[$id] = require $file;
        }

        return $features;
    }

    private function loadRoutes(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $collection = new FeatureRouteCollection;
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $collection->add($route);
        }
        $router->setRoutes($collection);
        $groups = [
            'web' => ['middleware' => ['web', 'auth', 'verified', 'tenant']],
            'guest' => ['middleware' => ['web', 'guest']],
            'auth' => ['middleware' => ['web', 'auth']],
            'api' => ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum', 'tenant.token', 'throttle:api']],
            'public' => ['middleware' => ['throttle:public']],
            'webhook' => ['prefix' => 'webhooks', 'middleware' => ['api', 'throttle:webhook']],
            'dev' => ['prefix' => 'dev', 'middleware' => ['web', EnsureLocalPreview::class]],
            'preview' => ['prefix' => 'preview', 'middleware' => ['web', EnsureLocalPreview::class]],
        ];

        $this->assertUniqueRouteNames($router);
        foreach ($this->files($this->app->basePath('routes/features').'/*.php') as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            $this->assertId($id);
            $registrations = require $file;
            if (! is_array($registrations)) {
                throw new LogicException("Feature routes must return an array: {$id}");
            }
            foreach ($registrations as $group => $register) {
                if (! isset($groups[$group]) || ! $register instanceof Closure) {
                    throw new LogicException("Invalid route group/callback in {$id}: {$group}");
                }
                $existingNames = [];
                foreach ($router->getRoutes()->getRoutes() as $route) {
                    if ($route->getName() !== null) {
                        $existingNames[$route->getName()] = $route;
                    }
                }
                $routeCount = count($collection->registeredRoutes());
                $router->group($groups[$group], $register);
                foreach (array_slice($collection->registeredRoutes(), $routeCount) as $route) {
                    if (! is_string($route->getAction('uses')) || ! is_string($route->getAction('controller'))) {
                        throw new LogicException("Feature route handlers must be controller actions: {$id} {$route->uri()}");
                    }
                }
                foreach ($router->getRoutes()->getRoutes() as $route) {
                    $name = $route->getName();
                    if ($name !== null && isset($existingNames[$name]) && $existingNames[$name] !== $route) {
                        throw new LogicException("Duplicate feature route name: {$name}");
                    }
                }
                $this->assertUniqueRouteNames($router);
            }
        }
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    private function assertUniqueRouteNames(Router $router): void
    {
        $names = [];
        $collection = $router->getRoutes();
        $routes = $collection instanceof FeatureRouteCollection ? $collection->registeredRoutes() : $collection->getRoutes();
        foreach ($routes as $route) {
            if (($name = $route->getName()) !== null) {
                $this->assertNewKey($names, $name, 'route name');
                $names[$name] = true;
            }
        }
    }

    private function discoverMigrations(): array
    {
        $base = $this->app->databasePath('migrations');
        $directories = [];
        foreach ($this->files($base.'/*', GLOB_ONLYDIR) as $directory) {
            $id = basename($directory);
            if ($id !== 'bootstrap') {
                $this->assertId($id);
            }
            $directories[] = $directory;
        }
        $basenames = [];
        foreach (array_merge([$base], $directories) as $directory) {
            foreach ($this->files($directory.'/*.php') as $file) {
                $name = basename($file);
                $this->assertNewKey($basenames, $name, 'migration basename');
                $basenames[$name] = $file;
            }
        }

        return $directories;
    }

    private function discoverTranslations(): array
    {
        $base = $this->app->langPath('ru');
        if (! is_dir($base)) {
            return [];
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);
        $lines = [];
        foreach ($files as $file) {
            $namespace = substr(str_replace('\\', '/', substr($file, strlen($base) + 1)), 0, -4);
            $values = require $file;
            if (! is_array($values)) {
                throw new LogicException("Translations must return an array: {$namespace}");
            }
            $this->flattenTranslations($values, $namespace, $lines);
        }

        return $lines;
    }

    private function flattenTranslations(array $values, string $prefix, array &$lines): void
    {
        foreach ($values as $key => $value) {
            $path = $prefix.'.'.$key;
            if (is_array($value)) {
                $this->flattenTranslations($value, $path, $lines);
            } elseif (is_string($value)) {
                $this->assertNewKey($lines, $path, 'translation key');
                $lines[$path] = $value;
            } else {
                throw new LogicException("Translation must be a string: {$path}");
            }
        }
    }

    private function afterResolving(string $abstract, Closure $callback): void
    {
        $this->app->afterResolving($abstract, $callback);
        if ($this->app->resolved($abstract)) {
            $callback($this->app->make($abstract));
        }
    }

    private function files(string $pattern, int $flags = 0): array
    {
        $files = glob($pattern, $flags) ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    private function assertId(string $id): void
    {
        if (! preg_match(self::ID_PATTERN, $id)) {
            throw new LogicException("Invalid feature ID: {$id}");
        }
    }

    private function assertNewKey(array $existing, mixed $key, string $kind): void
    {
        if (! is_string($key) || $key === '' || array_key_exists($key, $existing)) {
            throw new LogicException("Duplicate or invalid feature {$kind}: ".(string) $key);
        }
    }

    private function assertClass(mixed $class, ?string $contract = null): void
    {
        if (! is_string($class) || ! class_exists($class) || ($contract !== null && ! is_a($class, $contract, true))) {
            throw new LogicException('Invalid feature class: '.(is_string($class) ? $class : get_debug_type($class)));
        }
    }

    private function assertSerializable(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->assertSerializable($child, $path.'.'.$key);
            }
        } elseif (! is_scalar($value) && $value !== null) {
            throw new LogicException("Non-cacheable feature configuration: {$path}");
        }
    }
}
