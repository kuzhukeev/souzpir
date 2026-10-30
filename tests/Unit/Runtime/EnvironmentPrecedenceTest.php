<?php

namespace Tests\Unit\Runtime;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class EnvironmentPrecedenceTest extends TestCase
{
    public function test_inherited_process_environment_wins_over_dotenv_defaults(): void
    {
        $project = dirname(__DIR__, 3);
        $temporaryRoot = $project.'/.local/tmp';
        $fixture = $temporaryRoot.'/runtime-env-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $files->makeDirectory($fixture, 0700, true);

        try {
            file_put_contents($fixture.'/.env.testing', "APP_ENV=testing\nDB_DATABASE=example_preview\n");
            $script = 'require '.var_export($project.'/vendor/autoload.php', true).';'
                .'require '.var_export($project.'/bootstrap/runtime.php', true).';'
                .'$fixture = '.var_export($fixture, true).';'
                .<<<'PHP'
                // cli-server can expose inherited values only through getenv.
                foreach (['APP_ENV', 'DB_DATABASE', 'APP_STORAGE_PATH', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'VIEW_COMPILED_PATH'] as $key) {
                    unset($_ENV[$key], $_SERVER[$key]);
                    if (! in_array($key, ['APP_ENV', 'DB_DATABASE'], true)) {
                        putenv($key);
                    }
                }
                $runtime = prepareRuntime($fixture);
                echo json_encode([Illuminate\Support\Env::get('DB_DATABASE'), $runtime['storage']], JSON_THROW_ON_ERROR);
                PHP;
            $process = new Process([PHP_BINARY, '-r', $script], $project, [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => 'souzpir_test_ci',
            ]);
            $process->mustRun();

            self::assertSame([
                'souzpir_test_ci',
                str_replace('\\', '/', $fixture).'/.local/runtime/testing/storage',
            ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $resolved = str_replace('\\', '/', realpath($fixture));
            $boundary = str_replace('\\', '/', realpath($temporaryRoot)).'/';
            self::assertStringStartsWith($boundary, $resolved);
            $files->deleteDirectory($fixture);
        }
    }
}
