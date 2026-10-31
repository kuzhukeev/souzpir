<?php

namespace Tests\Feature\Shell\Profile;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\MfaTestDatabase;
use Tests\TestCase;

final class ProfileFeatureCacheTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_route_cache_survives_disabled_to_enabled_config_rebuild(): void
    {
        $tmpRoot = realpath(base_path('.local/runtime'));
        self::assertIsString($tmpRoot);
        $temp = $tmpRoot.DIRECTORY_SEPARATOR.'profile-feature-cache-'.Str::random(16);
        self::assertTrue(mkdir($temp, 0700));
        $environment = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $temp.'/config.php', 'APP_ROUTES_CACHE' => $temp.'/routes.php',
            'APP_EVENTS_CACHE' => $temp.'/events.php', 'APP_SERVICES_CACHE' => $temp.'/services.php',
            'APP_PACKAGES_CACHE' => $temp.'/packages.php',
        ];
        try {
            $this->runProcess(['artisan', 'config:cache'], [...$environment, 'PROFILE_SETTINGS_ENABLED' => 'false']);
            $this->runProcess(['artisan', 'route:cache'], [...$environment, 'PROFILE_SETTINGS_ENABLED' => 'false']);
            $before = json_decode($this->runProcess([__DIR__.'/Fixtures/profile-feature-cache-worker.php'], [...$environment, 'PROFILE_SETTINGS_ENABLED' => 'false']), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['config_cached' => true, 'routes_cached' => true, 'configured' => false, 'ready' => false, 'guarded_route' => true], $before);
            // Config cache is immutable until rebuilt, even if the environment changes.
            $unchanged = json_decode($this->runProcess([__DIR__.'/Fixtures/profile-feature-cache-worker.php'], [...$environment, 'PROFILE_SETTINGS_ENABLED' => 'true']), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($before, $unchanged);
            $this->runProcess(['artisan', 'config:cache'], [...$environment, 'PROFILE_SETTINGS_ENABLED' => 'true']);
            $after = json_decode($this->runProcess([__DIR__.'/Fixtures/profile-feature-cache-worker.php'], [...$environment, 'PROFILE_SETTINGS_ENABLED' => 'true']), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['config_cached' => true, 'routes_cached' => true, 'configured' => true, 'ready' => true, 'guarded_route' => true], $after);
        } finally {
            $resolved = realpath($temp);
            if (is_string($resolved) && str_starts_with(strtolower($resolved), strtolower($tmpRoot.DIRECTORY_SEPARATOR.'profile-feature-cache-'))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        }
    }

    private function runProcess(array $arguments, array $environment): string
    {
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), ...$arguments], base_path(), array_merge(getenv(), $environment), timeout: 30);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return trim($process->getOutput());
    }
}
