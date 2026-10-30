<?php

use Symfony\Component\Console\Input\ArgvInput;

/** Establish writable paths before framework configuration is loaded. */
function prepareRuntime(string $basePath): array
{
    $environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: null);
    if (PHP_SAPI === 'cli') {
        $input = new ArgvInput;
        if ($input->hasParameterOption('--env')) {
            $environment = $input->getParameterOption('--env');
        }
    }
    if ($environment !== null && ! preg_match('/^[A-Za-z0-9_-]+$/D', $environment)) {
        throw new RuntimeException('Invalid environment name.');
    }
    $testing = $environment === 'testing';
    $environmentFile = $environment && is_file($basePath.'/.env.'.$environment) ? '.env.'.$environment : '.env';
    if ($testing) {
        // Never silently fall back to preview credentials for artisan --env=testing.
        $environmentFile = '.env.testing';
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
    }
    Dotenv\Dotenv::createImmutable($basePath, $environmentFile)->safeLoad();
    $root = str_replace('\\', '/', $basePath).'/.local/runtime';
    $assertAncestorInside = static function (string $directory, string $boundary): void {
        $ancestor = $directory;
        while (! file_exists($ancestor) && ! is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                throw new RuntimeException('Runtime has no existing parent directory.');
            }
            $ancestor = $parent;
        }
        $resolved = realpath($ancestor);
        if ($resolved === false) {
            throw new RuntimeException('Runtime contains an unresolved link.');
        }
        $resolved = strtolower(str_replace('\\', '/', $resolved));
        $boundary = strtolower(str_replace('\\', '/', $boundary));
        if ($resolved !== $boundary && ! str_starts_with($resolved, $boundary.'/')) {
            throw new RuntimeException('Runtime directory resolves outside this checkout.');
        }
    };
    $assertAncestorInside($root, realpath($basePath));
    if (! is_dir($root) && ! mkdir($root, 0775, true) && ! is_dir($root)) {
        throw new RuntimeException('Cannot create isolated runtime root.');
    }
    $default = $root.($testing ? '/testing' : '');
    $read = static fn (string $key, string $fallback): string => (string) ($_SERVER[$key] ?? $_ENV[$key] ?? (getenv($key) ?: $fallback));
    $paths = [
        'APP_STORAGE_PATH' => $read('APP_STORAGE_PATH', $default.'/storage'),
        'APP_CONFIG_CACHE' => $read('APP_CONFIG_CACHE', $default.'/cache/config.php'),
        'APP_ROUTES_CACHE' => $read('APP_ROUTES_CACHE', $default.'/cache/routes.php'),
        'APP_EVENTS_CACHE' => $read('APP_EVENTS_CACHE', $default.'/cache/events.php'),
        'APP_SERVICES_CACHE' => $read('APP_SERVICES_CACHE', $default.'/cache/services.php'),
        'APP_PACKAGES_CACHE' => $read('APP_PACKAGES_CACHE', $default.'/cache/packages.php'),
        'VIEW_COMPILED_PATH' => $read('VIEW_COMPILED_PATH', $default.'/storage/framework/views'),
    ];
    foreach ($paths as $key => $path) {
        $path = str_replace('\\', '/', $path);
        if (preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || ! str_starts_with(strtolower($path), strtolower($root).'/')) {
            throw new RuntimeException($key.' must be an absolute path inside this checkout .local/runtime directory.');
        }
        $directory = in_array($key, ['APP_STORAGE_PATH', 'VIEW_COMPILED_PATH'], true) ? $path : dirname($path);
        $assertAncestorInside($directory, realpath($root));
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the isolated runtime directory.');
        }
        $resolved = str_replace('\\', '/', realpath($directory));
        $resolvedRoot = str_replace('\\', '/', realpath($root));
        if ($resolved !== $resolvedRoot && ! str_starts_with(strtolower($resolved), strtolower($resolvedRoot).'/')) {
            throw new RuntimeException('Runtime directory resolves outside this checkout.');
        }
        $_ENV[$key] = $_SERVER[$key] = $paths[$key] = $path;
    }
    foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/cache/locks', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
        $path = $paths['APP_STORAGE_PATH'].'/'.$directory;
        $assertAncestorInside($path, realpath($root));
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot initialize isolated storage.');
        }
    }

    return ['storage' => $paths['APP_STORAGE_PATH']];
}
