<?php

namespace Tests\Feature\Shell\Data;

use App\Shell\Data\ExportFileLock;
use App\Shell\Data\PruneExports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ExportFairPruningTest extends TenancyTestCase
{
    public function test_global_cleanup_advances_past_unrelated_directories(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        for ($i = 0; $i < 20; $i++) {
            $disk->makeDirectory('data-exports/unrelated-'.$i);
        }
        [$owner, $org, $brand] = $this->cabinet();
        $id = (string) Str::ulid();
        $path = 'data-exports/'.$org->id.'/'.$id.'.zip';
        $disk->put($path, 'expired synthetic export');
        DB::table('data_exports')->insert([
            'id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
            'period_to' => '2026-01-02', 'path' => $path, 'sha256' => str_repeat('a', 64),
            'expires_at' => now()->subMinute(), 'created_at' => now()->subDay(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < 40 && $disk->exists($path); $i++) {
            app(PruneExports::class)->prune(1, 60);
        }

        $disk->assertMissing($path);
        $this->assertDatabaseMissing('data_exports', ['id' => $id]);
        self::assertDirectoryExists($disk->path('data-exports/unrelated-0'));
    }

    public function test_a_locked_oldest_archive_does_not_starve_later_expired_archives(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $disk = Storage::disk('local');
        $prefix = 'data-exports/'.$org->id.'/';
        $disk->makeDirectory($prefix);
        $firstId = (string) Str::ulid();
        $held = ExportFileLock::acquireForExport($disk->path($prefix), $firstId, LOCK_SH);
        self::assertIsResource($held);
        $ids = [$firstId];
        // Avoid choosing another ID on the held stripe; different exports may
        // intentionally share a bounded lock stripe.
        while (count($ids) < 3) {
            $candidate = (string) Str::ulid();
            $probe = ExportFileLock::acquireForExport($disk->path($prefix), $candidate, LOCK_EX | LOCK_NB);
            if ($probe !== false) {
                fclose($probe);
                $ids[] = $candidate;
            }
        }
        for ($i = 0; $i < 3; $i++) {
            $id = $ids[$i];
            $disk->put($prefix.$id.'.zip', 'expired synthetic export');
            DB::table('data_exports')->insert([
                'id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
                'period_to' => '2026-01-02', 'path' => $prefix.$id.'.zip', 'sha256' => str_repeat('a', 64),
                'expires_at' => now()->subMinutes(10 - $i), 'created_at' => now()->subDay(), 'updated_at' => now(),
            ]);
        }

        try {
            for ($i = 0; $i < 10; $i++) {
                app(PruneExports::class)->pruneOrganization($org->id, 1, 5000);
            }
            $disk->assertExists($prefix.$ids[0].'.zip');
            $this->assertDatabaseHas('data_exports', ['id' => $ids[0]]);
            foreach (array_slice($ids, 1) as $id) {
                $disk->assertMissing($prefix.$id.'.zip');
                $this->assertDatabaseMissing('data_exports', ['id' => $id]);
            }
        } finally {
            fclose($held);
        }
        for ($i = 0; $i < 10 && $disk->exists($prefix.$ids[0].'.zip'); $i++) {
            app(PruneExports::class)->pruneOrganization($org->id, 1, 5000);
        }
        $disk->assertMissing($prefix.$ids[0].'.zip');
        $this->assertDatabaseMissing('data_exports', ['id' => $ids[0]]);
    }

    public function test_expired_and_orphan_exports_are_not_starved_by_legacy_lock_files(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $disk = Storage::disk('local');
        $prefix = 'data-exports/'.$org->id.'/';
        $disk->makeDirectory($prefix);

        // A previous version left one sentinel per export. Their number must not
        // consume the small batch intended for actual export cleanup.
        for ($i = 0; $i < 200; $i++) {
            $disk->put($prefix.(string) Str::ulid().'.lock', '');
        }

        $expired = [];
        for ($i = 0; $i < 3; $i++) {
            $id = (string) Str::ulid();
            $expired[] = $id;
            $disk->put($prefix.$id.'.zip', 'expired synthetic export');
            DB::table('data_exports')->insert([
                'id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
                'period_to' => '2026-01-02', 'path' => $prefix.$id.'.zip', 'sha256' => str_repeat('a', 64),
                'expires_at' => now()->subMinute(), 'created_at' => now()->subDay(), 'updated_at' => now(),
            ]);
        }
        $orphan = $prefix.(string) Str::ulid().'.jsonl.tmp';
        $disk->put($orphan, 'abandoned synthetic data');
        touch($disk->path($orphan), now()->subHours(2)->getTimestamp());

        for ($i = 0; $i < 10; $i++) {
            app(PruneExports::class)->pruneOrganization($org->id, 2, 5000);
        }

        foreach ($expired as $id) {
            $disk->assertMissing($prefix.$id.'.zip');
            $this->assertDatabaseMissing('data_exports', ['id' => $id]);
        }
        $disk->assertMissing($orphan);
    }

    public function test_lock_stripes_stay_bounded_and_an_active_export_is_not_removed(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $disk = Storage::disk('local');
        $prefix = 'data-exports/'.$org->id.'/';
        $disk->makeDirectory($prefix);
        $directory = $disk->path($prefix);

        for ($i = 0; $i < 200; $i++) {
            $lock = ExportFileLock::acquireForExport($directory, (string) Str::ulid(), LOCK_SH);
            self::assertIsResource($lock);
            fclose($lock);
        }
        $lockFiles = glob($directory.DIRECTORY_SEPARATOR.'.locks'.DIRECTORY_SEPARATOR.'*.lock') ?: [];
        self::assertLessThanOrEqual(64, count($lockFiles));

        $id = (string) Str::ulid();
        $disk->put($prefix.$id.'.zip', 'active synthetic export');
        DB::table('data_exports')->insert([
            'id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
            'period_to' => '2026-01-02', 'path' => $prefix.$id.'.zip', 'sha256' => str_repeat('a', 64),
            'expires_at' => now()->subMinute(), 'created_at' => now()->subDay(), 'updated_at' => now(),
        ]);
        $held = ExportFileLock::acquireForExport($directory, $id, LOCK_SH);
        self::assertIsResource($held);
        try {
            for ($i = 0; $i < 5; $i++) {
                app(PruneExports::class)->pruneOrganization($org->id, 2, 5000);
            }
            $disk->assertExists($prefix.$id.'.zip');
            $this->assertDatabaseHas('data_exports', ['id' => $id]);
        } finally {
            fclose($held);
        }
        for ($i = 0; $i < 10 && $disk->exists($prefix.$id.'.zip'); $i++) {
            app(PruneExports::class)->pruneOrganization($org->id, 2, 5000);
        }
        $disk->assertMissing($prefix.$id.'.zip');
        $this->assertDatabaseMissing('data_exports', ['id' => $id]);
    }
}
