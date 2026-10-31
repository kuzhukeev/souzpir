<?php

namespace Tests\Feature\Shell\Data;

use App\Shell\Data\PruneExports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ExportPruneDeadlineTest extends TenancyTestCase
{
    public function test_expired_selection_makes_progress_when_query_exceeds_soft_budget(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        $disk = Storage::disk('local');
        $id = (string) Str::ulid();
        $path = 'data-exports/'.$org->id.'/'.$id.'.zip';
        $disk->put($path, 'expired synthetic export');
        DB::table('data_exports')->insert([
            'id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $owner->id, 'module' => 'seller', 'period_from' => '2026-01-01',
            'period_to' => '2026-01-02', 'path' => $path, 'sha256' => str_repeat('a', 64),
            'expires_at' => now()->subMinute(), 'created_at' => now()->subDay(), 'updated_at' => now(),
        ]);

        $delayed = false;
        DB::listen(static function ($query) use (&$delayed): void {
            if (! $delayed && str_contains(strtolower($query->sql), 'select')
                && str_contains(strtolower($query->sql), 'data_exports')) {
                $delayed = true;
                usleep(50000);
            }
        });
        app(PruneExports::class)->pruneOrganization($org->id, 1, 5);

        self::assertTrue($delayed, 'The test must delay the export selection query.');
        $disk->assertMissing($path);
        $this->assertDatabaseMissing('data_exports', ['id' => $id]);
    }
}
