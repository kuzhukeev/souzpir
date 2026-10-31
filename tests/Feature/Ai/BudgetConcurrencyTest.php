<?php

namespace Tests\Feature\Ai;

use App\Ai\CanonicalJson;
use App\Contracts\Ai\AiRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BudgetConcurrencyTest extends TestCase
{
    public function test_two_processes_cannot_exceed_the_same_budget(): void
    {
        $this->race(false);
    }

    public function test_same_key_concurrent_calls_share_one_ledger_entry(): void
    {
        $this->race(true);
    }

    public function test_one_failed_attempt_cannot_fork_into_two_concurrent_retries(): void
    {
        $this->race(false, true);
    }

    private function race(bool $sameKey, bool $retry = false): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $organization = (string) Str::ulid();
        $brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $organization, 'name' => 'Concurrency fixture', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $organization, 'name' => 'Test', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
        DB::table('ai_budgets')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand, 'family' => 'seller', 'period' => now('UTC')->startOfMonth()->toDateString(), 'limit_micros' => 100, 'spent_micros' => 0, 'reserved_micros' => 0, 'unresolved_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $parent = null;
        if ($retry) {
            $parent = (string) Str::ulid();
            DB::table('ai_calls')->insert(['id' => $parent, 'organization_id' => $organization, 'brand_id' => $brand, 'budget_id' => DB::table('ai_budgets')->where('organization_id', $organization)->value('id'), 'idempotency_key_hash' => hash('sha256', 'parent'), 'request_hash' => hash('sha256', 'parent-request'), 'family' => 'seller', 'purpose' => 'responder', 'provider' => 'gemini', 'requested_model' => 'google/gemini-2.5-flash', 'model' => 'google/gemini-2.5-flash', 'object_type' => 'brand', 'object_id' => $brand, 'data_classes' => '["knowledge_public"]', 'attempt' => 1, 'status' => 'failed', 'reserved_micros' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $directory = storage_path('app/race-'.Str::random(12));
        mkdir($directory, 0700, true);
        $request = new AiRequest([['role' => 'user', 'content' => 'concurrent test']], 'google/gemini-2.5-flash', 0.5);
        $result = ['content' => 'test', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 60];
        file_put_contents($directory.'/'.CanonicalJson::hash($request->toArray()).'.json', json_encode(['result' => $result], JSON_THROW_ON_ERROR));
        $barrier = $directory.'/start';
        $workers = [];
        try {
            foreach (($sameKey ? ['same', 'same'] : ['first', 'second']) as $key) {
                $cmd = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/race-worker.php', $organization, $brand, $key, $directory, $barrier];
                if ($parent) {
                    $cmd[] = $parent;
                }
                $environment = array_merge(getenv(), ['APP_ENV' => 'testing']);
                $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            file_put_contents($barrier, 'go');
            $states = [];
            $ids = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                self::assertSame(0, $exit, $error);
                $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                $states[] = $decoded['status'];
                if (isset($decoded['id'])) {
                    $ids[] = $decoded['id'];
                }
            }
            sort($states);
            if ($sameKey) {
                self::assertContains($states, [['completed', 'completed'], ['completed', 'in_progress']]);
                self::assertCount(2, $ids);
                self::assertSame($ids[0], $ids[1]);
            } else {
                self::assertSame(['blocked', 'completed'], $states);
            }
            self::assertSame(60, (int) DB::table('ai_budgets')->where('organization_id', $organization)->value('spent_micros'));
            self::assertSame(0, (int) DB::table('ai_budgets')->where('organization_id', $organization)->value('reserved_micros'));
            self::assertSame($retry ? 2 : 1, DB::table('ai_calls')->where('organization_id', $organization)->count());
            if ($retry) {
                self::assertSame(1, DB::table('ai_calls')->where('parent_call_id', $parent)->count());
            }
        } finally {
            DB::table('object_costs')->where('organization_id', $organization)->delete();
            DB::table('ai_calls')->where('organization_id', $organization)->whereNotNull('parent_call_id')->delete();
            DB::table('ai_calls')->where('organization_id', $organization)->delete();
            DB::table('ai_budgets')->where('organization_id', $organization)->delete();
            DB::table('brands')->where('organization_id', $organization)->delete();
            DB::table('organizations')->where('id', $organization)->delete();
        }
    }
}
