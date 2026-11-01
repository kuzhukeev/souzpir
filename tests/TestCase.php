<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // A linked vendor directory can make Composer resolve the integration
        // checkout instead of the current worktree. Fail before migrations run.
        $expected = realpath(dirname(__DIR__));
        $cwd = realpath(getcwd());
        $inferred = realpath(Application::inferBasePath());
        $samePath = static fn (string $a, string $b): bool => DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($a, $b) === 0 : strcmp($a, $b) === 0;
        if ($expected === false || $cwd === false || $inferred === false
            || ! $samePath($expected, $cwd) || ! $samePath($expected, $inferred)) {
            throw new \RuntimeException('Test application root does not match the current worktree.');
        }

        // Artisan may bootstrap the local environment before spawning PHPUnit.
        // Do not inherit its storage/cache paths into the isolated test runtime.
        $runtime = str_replace('\\', '/', $expected).'/.local/runtime/testing';
        $parallelToken = $_SERVER['TEST_TOKEN'] ?? null;
        $parallelWorker = ! empty($_SERVER['LARAVEL_PARALLEL_TESTING'])
            && is_string($parallelToken) && ctype_digit($parallelToken);
        if ($parallelWorker) {
            $runtime .= '/workers/'.$parallelToken;
        }
        foreach ([
            'APP_STORAGE_PATH' => $runtime.'/storage',
            'APP_CONFIG_CACHE' => $runtime.'/cache/config.php',
            'APP_ROUTES_CACHE' => $runtime.'/cache/routes.php',
            'APP_EVENTS_CACHE' => $runtime.'/cache/events.php',
            'APP_SERVICES_CACHE' => $runtime.'/cache/services.php',
            'APP_PACKAGES_CACHE' => $runtime.'/cache/packages.php',
            'VIEW_COMPILED_PATH' => $runtime.'/storage/framework/views',
        ] as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            if ($parallelWorker) {
                putenv($key.'='.$value);
            }
        }

        // CI provisions a dedicated database for each parallel process. Boot
        // against the validated base, then select the worker before any test
        // fixture or subclass guard can connect to it.
        static $parallelBaseDatabase = null;
        $token = $_SERVER['TEST_TOKEN'] ?? null;
        $workerDatabase = null;
        if (! empty($_SERVER['LARAVEL_PARALLEL_TESTING'])
            && ! empty($_SERVER['LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES'])
            && is_string($token) && ctype_digit($token)) {
            $parallelBaseDatabase ??= getenv('DB_DATABASE');
            if (is_string($parallelBaseDatabase)
                && preg_match('/\Asouzpir_test_[a-z0-9_]+\z/D', $parallelBaseDatabase)) {
                $candidate = $parallelBaseDatabase.'_test_'.$token;
                $configured = getenv('DB_TEST_DATABASE');
                if ($configured === $parallelBaseDatabase || $configured === $candidate) {
                    $workerDatabase = $candidate;
                    foreach (['DB_DATABASE', 'DB_TEST_DATABASE'] as $key) {
                        putenv($key.'='.$parallelBaseDatabase);
                        $_ENV[$key] = $_SERVER[$key] = $parallelBaseDatabase;
                    }
                }
            }
        }

        $app = parent::createApplication();
        if ($workerDatabase !== null) {
            foreach (['DB_DATABASE', 'DB_TEST_DATABASE'] as $key) {
                putenv($key.'='.$workerDatabase);
                $_ENV[$key] = $_SERVER[$key] = $workerDatabase;
            }
            $app['config']->set('runtime.test_database', $workerDatabase);
            $app['config']->set('database.connections.pgsql.database', $workerDatabase);
            $app['db']->purge('pgsql');
        }

        return $app;
    }
}
