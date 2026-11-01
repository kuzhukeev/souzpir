<?php

namespace Tests\Feature\Shell\Data;

use App\Providers\Features\FeatureRegistry;
use App\Shell\Data\Contracts\ExportContributor;
use App\Shell\Data\Contracts\ExportQuery;
use App\Shell\Data\Contracts\ExportRecord;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tenancy\TenancyTestCase;
use ZipArchive;

final class SyntheticSellerExport implements ExportContributor
{
    public function module(): string
    {
        return 'seller';
    }

    public function stream(TenantContext $tenant, ExportQuery $query): iterable
    {
        yield new ExportRecord('fixture', ['organization' => $tenant->organizationId, 'brand' => $tenant->brandId,
            'from' => $query->from->toIso8601String(), 'until' => $query->until->toIso8601String()]);
    }
}

final class DataExportTest extends TenancyTestCase
{
    private function registerSyntheticContributor(): void
    {
        $registry = app(FeatureRegistry::class);
        $property = new \ReflectionProperty(FeatureRegistry::class, 'extensions');
        $property->setValue($registry, array_merge($registry->extensions(), ['export.seller' => SyntheticSellerExport::class]));
    }

    public function test_archive_context_checksum_integrity_expiry_and_tenant_isolation(): void
    {
        Storage::fake('local');
        $this->registerSyntheticContributor();
        [$owner, $org, $brand, $sibling] = $this->cabinet();
        [$foreignOwner, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/settings/data/exports', ['module' => 'seller', 'from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertRedirect('/settings/data')->assertSessionHasNoErrors();

        $row = DB::table('data_exports')->sole();
        self::assertSame($org->id, $row->organization_id);
        self::assertSame($brand->id, $row->brand_id);
        self::assertSame($owner->id, $row->user_id);
        $path = Storage::disk('local')->path($row->path);
        self::assertSame($row->sha256, hash_file('sha256', $path));
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path));
        $record = json_decode(trim($zip->getFromName('records.jsonl')), true, flags: JSON_THROW_ON_ERROR);
        $zip->close();
        self::assertSame($org->id, $record['data']['organization']);
        self::assertSame($brand->id, $record['data']['brand']);
        self::assertSame('2025-12-31T21:00:00+00:00', $record['data']['from']);
        self::assertSame('2026-01-31T21:00:00+00:00', $record['data']['until']);
        $url = '/settings/data/exports/'.$row->id.'/download';
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'subject_id' => $row->id, 'action' => 'data.export.download_requested']);
        self::assertSame(1, DB::table('audit_logs')->where('subject_id', $row->id)->where('action', 'data.export.download_requested')->count());
        $this->post('/brand/switch', ['brand_id' => $sibling->id])->assertRedirect();
        $this->get($url)->assertNotFound();
        config(['features.E0-02.defaults.organization_id' => $foreignOrg->id]);
        $this->actingAs($foreignOwner)->withSession(['brand_id' => $foreignBrand->id])->get($url)->assertNotFound();

        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        Storage::disk('local')->put($row->path, 'tampered');
        $this->get($url)->assertStatus(409);
        self::assertSame(1, DB::table('audit_logs')->where('subject_id', $row->id)->where('action', 'data.export.download_requested')->count());
        DB::table('data_exports')->where('id', $row->id)->update(['expires_at' => now()->subSecond()]);
        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'action' => 'data.export.created']);
    }

    public function test_non_owner_and_revoked_session_cannot_create_export(): void
    {
        Storage::fake('local');
        $this->registerSyntheticContributor();
        [, $org, $brand] = $this->cabinet();
        [$member] = $this->member($org, 'member', [$brand->id]);
        $payload = ['module' => 'seller', 'from' => '2026-01-01', 'to' => '2026-01-31'];
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->post('/settings/data/exports', $payload)->assertForbidden();
        self::assertSame(0, DB::table('data_exports')->count());
        DB::table('auth_session_registry')->where('user_id', $member->id)->update(['revoked_at' => now()]);
        $this->post('/settings/data/exports', $payload)->assertRedirect('/login');
        self::assertSame(0, DB::table('data_exports')->count());
    }
}
