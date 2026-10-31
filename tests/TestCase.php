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
        }

        return parent::createApplication();
    }
}
