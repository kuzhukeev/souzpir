<?php

namespace Tests\Unit\Logging;

use App\Logging\SanitizeRuntimeLog;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RuntimeLogTest extends TestCase
{
    public function test_console_processes_use_local_sanitized_log(): void
    {
        foreach (['queue:work', 'queue:listen', 'schedule:work', 'schedule:run', 'about'] as $command) {
            $root = dirname(__DIR__, 3);
            $storage = $root.'/.local/runtime/testing/log-probe-'.bin2hex(random_bytes(5));
            $process = new Process([PHP_BINARY, __DIR__.'/runtime-fixture.php', $command], $root, [
                'APP_ENV' => 'testing', 'APP_STORAGE_PATH' => $storage, 'LOG_CHANNEL' => 'stderr',
                'DB_URL' => '', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'souzpir_test_log_probe',
                'DB_TEST_DATABASE' => 'souzpir_test_log_probe', 'APP_PREVIEW_DATABASE' => 'souzpir_preview_log_probe',
            ]);
            $process->mustRun();
            if ($command === 'about') {
                self::assertSame('stderr', trim($process->getOutput()));

                continue;
            }
            self::assertStringStartsWith("runtime\n", $process->getOutput());
            $file = $storage.'/logs/runtime.log';
            self::assertFileExists($file);
            $log = file_get_contents($file);
            self::assertStringContainsString('Фоновый процесс', $log);
            self::assertStringNotContainsString('synthetic-secret', $log);
            self::assertStringNotContainsString('synthetic-password', $log);
            self::assertStringNotContainsString('/private/path', $log);
            unlink($file);
        }
    }

    public function test_runtime_log_discards_message_context_exception_and_paths(): void
    {
        $stream = fopen('php://memory', 'w+');
        $monolog = new Logger('runtime', [new StreamHandler($stream)]);
        $logger = new LaravelLogger($monolog);
        (new SanitizeRuntimeLog)($logger);
        $logger->error('synthetic-secret /private/path {token}', [
            'token' => 'synthetic-token',
            'exception' => new RuntimeException('synthetic-password /private/path'),
        ]);
        rewind($stream);
        $output = stream_get_contents($stream);
        self::assertStringContainsString('runtime.ERROR', $output);
        self::assertStringContainsString('Фоновый процесс', $output);
        foreach (['synthetic-secret', 'synthetic-token', 'synthetic-password', '/private/path', 'RuntimeException'] as $private) {
            self::assertStringNotContainsString($private, $output);
        }
    }
}
