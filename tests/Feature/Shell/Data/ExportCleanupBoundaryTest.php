<?php

namespace Tests\Feature\Shell\Data;

use App\Shell\Data\ExportFileLock;
use App\Shell\Data\PruneExports;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ExportCleanupBoundaryTest extends TenancyTestCase
{
    public function test_bounded_organization_scans_advance_and_skip_active_temporary_files(): void
    {
        Storage::fake('local');
        [, $org] = $this->cabinet();
        $disk = Storage::disk('local');
        $id = (string) Str::ulid();
        $prefix = 'data-exports/'.$org->id.'/';
        $temp = $prefix.$id.'.jsonl.tmp';
        $disk->put($temp, 'synthetic active data');
        touch($disk->path($temp), now()->subHours(2)->getTimestamp());
        $held = ExportFileLock::acquireForExport($disk->path($prefix), $id, LOCK_SH);
        self::assertIsResource($held);
        try {
            for ($i = 0; $i < 12; $i++) {
                app(PruneExports::class)->pruneOrganization($org->id, 1, 5000);
            }
            $disk->assertExists($temp);
        } finally {
            fclose($held);
        }
        for ($i = 0; $i < 12 && $disk->exists($temp); $i++) {
            app(PruneExports::class)->pruneOrganization($org->id, 1, 5000);
        }
        $disk->assertMissing($temp);
    }

    public function test_cleanup_does_not_follow_a_symlink_inside_the_export_directory(): void
    {
        Storage::fake('local');
        [, $org] = $this->cabinet();
        $disk = Storage::disk('local');
        $prefix = 'data-exports/'.$org->id.'/';
        $disk->makeDirectory($prefix);
        $target = $disk->path('must-remain.txt');
        file_put_contents($target, 'do not delete');
        $link = $disk->path($prefix.(string) Str::ulid().'.zip.tmp');
        if (! @symlink($target, $link)) {
            $this->markTestSkipped('Creating symlinks requires additional operating-system permissions.');
        }
        try {
            app(PruneExports::class)->pruneOrganization($org->id, 100, 5000);
            self::assertFileExists($target);
            self::assertSame('do not delete', file_get_contents($target));
            self::assertTrue(is_link($link));
        } finally {
            @unlink($link);
            @unlink($target);
        }
    }

    public function test_live_archive_is_preserved_even_if_it_is_old(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $disk = Storage::disk('local');
        $id = (string) Str::ulid();
        $path = 'data-exports/'.$org->id.'/'.$id.'.zip';
        $disk->put($path, 'live archive');
        touch($disk->path($path), now()->subDays(3)->getTimestamp());
        \Illuminate\Support\Facades\DB::table('data_exports')->insert([
            'id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
            'period_to' => '2026-01-02', 'path' => $path, 'sha256' => str_repeat('a', 64),
            'expires_at' => now()->addHour(), 'created_at' => now()->subDays(3), 'updated_at' => now(),
        ]);
        app(PruneExports::class)->pruneOrganization($org->id, 100, 5000);
        $disk->assertExists($path);
        $this->assertDatabaseHas('data_exports', ['id' => $id]);
    }
}
