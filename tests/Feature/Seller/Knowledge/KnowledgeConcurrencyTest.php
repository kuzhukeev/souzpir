<?php

namespace Tests\Feature\Seller\Knowledge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class KnowledgeConcurrencyTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function test_edit_waiting_for_publication_cannot_modify_published_content(): void
    {
        $org = (string) Str::ulid();
        $brand = (string) Str::ulid();
        $id = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $org, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $org, 'name' => 'Test', 'theme' => 'neutral', 'status' => 'active', 'settings' => '{}']);
        DB::table('knowledge_releases')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('kn_persona')->insert(['id' => (string) Str::ulid(), 'release_id' => $id, 'data' => '{"name":"original"}']);
        $path = base_path('.local/tmp/knowledge-concurrency-'.bin2hex(random_bytes(8)));
        mkdir($path, 0700, true);
        $script = __DIR__.'/Fixtures/ConcurrentKnowledgeWorker.php';
        $worker = fn ($mode) => new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), $script, $mode, $id, $path], base_path(), ['APP_ENV' => 'testing'], null, 20);
        $publish = $worker('publish');
        $edit = $worker('edit');
        try {
            $publish->start();
            $this->waitForFile($path.'/locked', $publish);
            $edit->start();
            $this->waitForFile($path.'/started', $edit);
            usleep(300000);
            self::assertTrue($edit->isRunning(), 'Concurrent editor should wait for publisher transaction');
            file_put_contents($path.'/release', '1');
            $publish->wait();
            $edit->wait();
            self::assertTrue($publish->isSuccessful(), $publish->getErrorOutput());
            self::assertTrue($edit->isSuccessful(), $edit->getErrorOutput());
            self::assertSame('original', json_decode(DB::table('kn_persona')->where('release_id', $id)->value('data'), true, 512, JSON_THROW_ON_ERROR)['name']);
        } finally {
            if ($publish->isRunning()) {
                $publish->stop(1);
            }
            if ($edit->isRunning()) {
                $edit->stop(1);
            }
            foreach (['locked', 'started', 'release'] as $file) {
                if (is_file($path.'/'.$file)) {
                    unlink($path.'/'.$file);
                }
            }
            rmdir($path);
        }
    }

    private function waitForFile(string $file, Process $worker): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($file) && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertFileExists($file, $worker->getOutput().' '.$worker->getErrorOutput());
    }
}
