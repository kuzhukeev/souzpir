<?php

namespace Tests\Feature\Shell\Profile;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

final class FileCacheLockTest extends TestCase
{
    #[Group('race')]
    public function test_separate_processes_serialize_a_file_cache_lock(): void
    {
        $barrier = base_path('.local/tmp/rollout-lock-'.Str::random(16));
        $key = 'rollout-lock-'.Str::random(16);
        $holder = null;
        $waiter = null;
        try {
            $holder = $this->start('holder', $key, $barrier);
            $this->waitFor($barrier.'.held');
            $waiter = $this->start('waiter', $key, $barrier);
            $this->waitFor($barrier.'.waiting');
            usleep(300000);
            self::assertFalse(is_file($barrier.'.acquired'), 'Second process acquired a held lock.');
            self::assertTrue(proc_get_status($waiter[0])['running']);
            file_put_contents($barrier.'.release', '1');
            $this->finish($holder);
            $holder = null;
            $this->finish($waiter);
            $waiter = null;
            self::assertFileExists($barrier.'.acquired');
        } finally {
            file_put_contents($barrier.'.release', '1');
            if ($holder !== null) {
                $this->finish($holder);
            }
            if ($waiter !== null) {
                $this->finish($waiter);
            }
            foreach (['held', 'waiting', 'release', 'acquired'] as $suffix) {
                if (is_file($barrier.'.'.$suffix)) {
                    unlink($barrier.'.'.$suffix);
                }
            }
        }
    }

    private function start(string $mode, string $key, string $barrier): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/file-cache-lock-worker.php', $mode, $key, $barrier];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing', 'CACHE_STORE' => 'file']));
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    private function waitFor(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path);
    }

    private function finish(array $worker): void
    {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error.$output);
    }
}
