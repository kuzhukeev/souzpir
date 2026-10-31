<?php

namespace Tests\Feature\Shell\Data;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PruneLegacyExportLocksCommandTest extends TestCase
{
    public function test_offline_confirmation_is_required_and_batches_only_remove_legacy_locks(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $org = (string) Str::ulid();
        $other = (string) Str::ulid();
        $prefix = 'data-exports/'.$org.'/';
        $legacy = [];
        for ($i = 0; $i < 7; ++$i) {
            $legacy[] = $prefix.(string) Str::ulid().'.lock';
            $disk->put(end($legacy), '');
        }
        $archive = $prefix.(string) Str::ulid().'.zip';
        $nonemptyLegacyName = $prefix.(string) Str::ulid().'.lock';
        $otherLock = 'data-exports/'.$other.'/'.(string) Str::ulid().'.lock';
        $disk->put($archive, 'archive');
        $disk->put($nonemptyLegacyName, 'preserve unexpected content');
        $disk->put($prefix.'.locks/00.lock', '');
        $disk->put($prefix.'.cleanup-expired-v2', 'cursor');
        $disk->put($prefix.'not-an-ulid.lock', 'keep');
        $disk->put($otherLock, '');

        $this->artisan('data:prune-legacy-export-locks', ['organization' => $org])->assertExitCode(1);
        foreach ($legacy as $path) $disk->assertExists($path);
        $this->artisan('data:prune-legacy-export-locks', ['organization' => $org, '--confirm-offline' => true, '--limit' => 2])->assertExitCode(0);
        self::assertSame(5, count(array_filter($legacy, fn ($path) => $disk->exists($path))));
        for ($i = 0; $i < 10; ++$i) {
            $this->artisan('data:prune-legacy-export-locks', ['organization' => $org, '--confirm-offline' => true, '--limit' => 2])->assertExitCode(0);
        }
        foreach ($legacy as $path) $disk->assertMissing($path);
        foreach ([$archive, $nonemptyLegacyName, $prefix.'.locks/00.lock', $prefix.'.cleanup-expired-v2', $prefix.'not-an-ulid.lock', $otherLock] as $path) $disk->assertExists($path);
    }

    public function test_invalid_organization_and_limit_are_rejected(): void
    {
        Storage::fake('local');
        $this->artisan('data:prune-legacy-export-locks', ['organization' => '../escape', '--confirm-offline' => true])->assertExitCode(1);
        $this->artisan('data:prune-legacy-export-locks', ['organization' => (string) Str::ulid(), '--confirm-offline' => true, '--limit' => 501])->assertExitCode(1);
    }

    public function test_legacy_symlink_is_not_followed(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $org = (string) Str::ulid();
        $prefix = 'data-exports/'.$org.'/';
        $disk->makeDirectory($prefix);
        $target = $disk->path('keep.txt');
        file_put_contents($target, 'keep');
        $link = $disk->path($prefix.(string) Str::ulid().'.lock');
        if (! @symlink($target, $link)) $this->markTestSkipped('Creating symlinks requires operating-system permissions.');
        try {
            $this->artisan('data:prune-legacy-export-locks', ['organization' => $org, '--confirm-offline' => true])->assertExitCode(0);
            self::assertSame('keep', file_get_contents($target));
            self::assertTrue(is_link($link));
        } finally {
            @unlink($link);
        }
    }
}
