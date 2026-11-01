<?php

namespace Tests\Feature\Shell\Data;

use App\Providers\Features\FeatureRegistry;
use App\Shell\Data\Contracts\ExportContributor;
use App\Shell\Data\Contracts\ExportQuery;
use App\Shell\Data\Contracts\ExportRecord;
use App\Shell\Data\ExportFileLock;
use App\Shell\Data\PruneExports;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SeparatedStorageFixtureContributor implements ExportContributor
{
    public function module(): string
    {
        return 'seller';
    }

    public function stream(TenantContext $tenant, ExportQuery $query): iterable
    {
        yield new ExportRecord('fixture', ['brand' => $tenant->brandId]);
    }
}

final class SeparatedExportStorageTest extends TenancyTestCase
{
    public function test_created_archive_uses_separate_directory_and_legacy_archive_remains_downloadable(): void
    {
        Storage::fake('local');
        $registry = app(FeatureRegistry::class);
        $property = new \ReflectionProperty(FeatureRegistry::class, 'extensions');
        $property->setValue($registry, array_merge($registry->extensions(), ['export.seller' => SeparatedStorageFixtureContributor::class]));
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/settings/data/exports', ['module' => 'seller', 'from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertRedirect('/settings/data')->assertSessionHasNoErrors();
        $row = DB::table('data_exports')->sole();
        self::assertSame('data-exports/'.$org->id.'/files/'.$row->id.'.zip', $row->path);
        Storage::disk('local')->assertExists($row->path);
        self::assertSame($row->sha256, hash_file('sha256', Storage::disk('local')->path($row->path)));
        $this->get('/settings/data/exports/'.$row->id.'/download')->assertOk();

        $legacyId = (string) Str::ulid();
        $legacyPath = 'data-exports/'.$org->id.'/'.$legacyId.'.zip';
        Storage::disk('local')->put($legacyPath, 'legacy synthetic archive');
        DB::table('data_exports')->insert([
            'id' => $legacyId, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
            'period_to' => '2026-01-31', 'path' => $legacyPath,
            'sha256' => hash('sha256', 'legacy synthetic archive'), 'expires_at' => now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/settings/data/exports/'.$legacyId.'/download')->assertOk();
    }

    public function test_current_orphan_and_expired_records_progress_with_many_legacy_locks(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $disk = Storage::disk('local');
        $prefix = 'data-exports/'.$org->id.'/';
        $disk->makeDirectory($prefix.'files');
        for ($i = 0; $i < 2048; $i++) {
            $disk->put($prefix.(string) Str::ulid().'.lock', '');
        }
        $orphan = $prefix.'files/'.(string) Str::ulid().'.jsonl.tmp';
        $disk->put($orphan, 'abandoned synthetic data');
        touch($disk->path($orphan), now()->subHours(2)->getTimestamp());
        $paths = [];
        foreach (['', 'files/'] as $subdir) {
            $id = (string) Str::ulid();
            $path = $prefix.$subdir.$id.'.zip';
            $disk->put($path, 'expired synthetic data');
            $paths[] = $path;
            DB::table('data_exports')->insert([
                'id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
                'period_to' => '2026-01-02', 'path' => $path, 'sha256' => str_repeat('a', 64),
                'expires_at' => now()->subMinute(), 'created_at' => now()->subDay(), 'updated_at' => now(),
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            app(PruneExports::class)->pruneOrganization($org->id, 1, 5000);
        }
        $disk->assertMissing($orphan);
        foreach ($paths as $path) {
            $disk->assertMissing($path);
        }
        self::assertSame(0, DB::table('data_exports')->where('organization_id', $org->id)->count());
    }

    public function test_locked_current_orphan_is_preserved_until_lock_release(): void
    {
        Storage::fake('local');
        [, $org] = $this->cabinet();
        $disk = Storage::disk('local');
        $prefix = 'data-exports/'.$org->id.'/';
        $disk->makeDirectory($prefix.'files');
        $id = (string) Str::ulid();
        $orphan = $prefix.'files/'.$id.'.zip.tmp';
        $disk->put($orphan, 'unfinished synthetic data');
        touch($disk->path($orphan), now()->subHours(2)->getTimestamp());
        $held = ExportFileLock::acquireForExport($disk->path($prefix), $id, LOCK_SH);
        self::assertIsResource($held);
        try {
            for ($i = 0; $i < 4; $i++) {
                app(PruneExports::class)->pruneOrganization($org->id, 1, 5000);
            }
            $disk->assertExists($orphan);
        } finally {
            fclose($held);
        }
        for ($i = 0; $i < 4 && $disk->exists($orphan); $i++) {
            app(PruneExports::class)->pruneOrganization($org->id, 1, 5000);
        }
        $disk->assertMissing($orphan);
    }

    public function test_download_and_cleanup_reject_noncanonical_archive_path(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $disk = Storage::disk('local');
        $prefix = 'data-exports/'.$org->id.'/';
        $id = (string) Str::ulid();
        $disk->put('foreign.zip', 'must remain');
        $unsafe = $prefix.'files/../../foreign.zip';
        DB::table('data_exports')->insert([
            'id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
            'period_to' => '2026-01-02', 'path' => $unsafe, 'sha256' => str_repeat('a', 64),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/settings/data/exports/'.$id.'/download')->assertNotFound();
        $disk->makeDirectory($prefix.'files');
        DB::table('data_exports')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        app(PruneExports::class)->pruneOrganization($org->id, 2, 5000);
        $disk->assertExists('foreign.zip');
        $this->assertDatabaseHas('data_exports', ['id' => $id]);
    }

    public function test_cleanup_does_not_follow_a_linked_files_directory(): void
    {
        Storage::fake('local');
        [, $org] = $this->cabinet();
        $disk = Storage::disk('local');
        $prefix = 'data-exports/'.$org->id.'/';
        $disk->makeDirectory($prefix);
        $disk->makeDirectory('other-data');
        $name = (string) Str::ulid().'.zip.tmp';
        $disk->put('other-data/'.$name, 'must remain');
        touch($disk->path('other-data/'.$name), now()->subHours(2)->getTimestamp());
        $link = $disk->path($prefix.'files');
        if (! @symlink($disk->path('other-data'), $link)) {
            $this->markTestSkipped('Creating symlinks requires additional operating-system permissions.');
        }
        try {
            app(PruneExports::class)->pruneOrganization($org->id, 10, 5000);
            $disk->assertExists('other-data/'.$name);
            self::assertTrue(is_link($link));
        } finally {
            @unlink($link);
        }
    }
}
