<?php

namespace Tests\Feature\Shell\Data;

use App\Shell\Data\ExportFileLock;
use App\Shell\Data\PruneExports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ExportCleanupTest extends TenancyTestCase
{
    public function test_prunes_expired_and_abandoned_files_but_skips_active_locked_files(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $disk = Storage::disk('local');
        $expired = (string) Str::ulid();
        $active = (string) Str::ulid();
        $orphan = (string) Str::ulid();
        $recent = (string) Str::ulid();
        $prefix = 'data-exports/'.$org->id.'/';
        foreach ([$expired, $active] as $id) {
            $disk->put($prefix.$id.'.zip', 'private synthetic data');
            DB::table('data_exports')->insert([
                'id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
                'period_to' => '2026-01-02', 'path' => $prefix.$id.'.zip', 'sha256' => str_repeat('a', 64),
                'expires_at' => now()->subMinute(), 'created_at' => now()->subDay(), 'updated_at' => now(),
            ]);
        }
        $disk->put($prefix.$orphan.'.jsonl.tmp', 'abandoned');
        touch($disk->path($prefix.$orphan.'.jsonl.tmp'), now()->subHours(2)->getTimestamp());
        $disk->put($prefix.$recent.'.zip.tmp', 'still recent');
        $disk->put($prefix.'unrelated.txt', 'not an export');
        $held = ExportFileLock::acquireForExport($disk->path($prefix), $active, LOCK_SH);
        self::assertIsResource($held);
        try {
            // Each pass has a soft time budget and a persisted cursor. Under CI
            // contention, the expired row and orphan file may need two passes.
            $removed = 0;
            for ($pass = 0; $pass < 5 && $removed < 2; $pass++) {
                $removed += app(PruneExports::class)->prune(100, 60);
            }
            self::assertSame(2, $removed);
            $disk->assertMissing($prefix.$expired.'.zip');
            $disk->assertMissing($prefix.$orphan.'.jsonl.tmp');
            $disk->assertExists($prefix.$active.'.zip');
            $disk->assertExists($prefix.$recent.'.zip.tmp');
            $disk->assertExists($prefix.'unrelated.txt');
            $this->assertDatabaseMissing('data_exports', ['id' => $expired]);
            $this->assertDatabaseHas('data_exports', ['id' => $active]);
        } finally {
            fclose($held);
        }
        self::assertSame(1, app(PruneExports::class)->prune(100, 60));
        $disk->assertMissing($prefix.$active.'.zip');
        $this->assertDatabaseMissing('data_exports', ['id' => $active]);
    }
}
