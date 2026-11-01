<?php

namespace Tests\Feature\Shell\InvitationIssuance;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InvitationIssuanceConfigCacheTest extends TestCase
{
    public function test_config_cache_keeps_stable_key_and_registers_the_endpoint(): void
    {
        $root = realpath(base_path('.local/runtime'));
        self::assertIsString($root);
        $directory = $root.DIRECTORY_SEPARATOR.'issuance-cache-'.bin2hex(random_bytes(16));
        self::assertTrue(mkdir($directory, 0700));
        $key = 'base64:'.base64_encode(random_bytes(32));
        $environment = [
            'APP_ENV' => 'testing', 'INVITATION_ISSUANCE_HMAC_KEY' => $key,
            'E106B_EXPECTED_KEY_HASH' => hash('sha256', $key),
            'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php',
            'APP_EVENTS_CACHE' => $directory.'/events.php', 'APP_SERVICES_CACHE' => $directory.'/services.php',
            'APP_PACKAGES_CACHE' => $directory.'/packages.php',
        ];
        try {
            $this->runProcess(['artisan', 'config:cache'], $environment);
            $fixture = [base_path('tests/Feature/Shell/InvitationIssuance/Fixtures/cache-worker.php')];
            $expected = ['cached' => true, 'key_matches' => true, 'route_registered' => true];
            self::assertSame($expected, json_decode($this->runProcess($fixture, $environment), true, flags: JSON_THROW_ON_ERROR));
            $environment['INVITATION_ISSUANCE_HMAC_KEY'] = 'base64:'.base64_encode(random_bytes(32));
            self::assertSame($expected, json_decode($this->runProcess($fixture, $environment), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            foreach (['config.php', 'routes.php', 'events.php', 'services.php', 'packages.php'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function runProcess(array $arguments, array $environment): string
    {
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), ...$arguments], base_path(), [...getenv(), ...$environment], timeout: 30);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return trim($process->getOutput());
    }
}
