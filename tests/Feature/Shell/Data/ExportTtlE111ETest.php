<?php

namespace Tests\Feature\Shell\Data;

use App\Providers\Features\FeatureRegistry;
use App\Shell\Data\Contracts\ExportContributor;
use App\Shell\Data\Contracts\ExportQuery;
use App\Shell\Data\Contracts\ExportRecord;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TtlSyntheticExport implements ExportContributor
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

final class ExportTtlE111ETest extends TenancyTestCase
{
    public function test_new_and_existing_expiries_configuration_permissions_and_brand_scope(): void
    {
        Storage::fake('local');
        $registry = app(FeatureRegistry::class);
        $property = new \ReflectionProperty(FeatureRegistry::class, 'extensions');
        $property->setValue($registry, array_merge($registry->extensions(), ['export.seller' => TtlSyntheticExport::class]));
        Carbon::setTestNow('2026-11-06 10:00:00');
        [$owner, $org, $brand, $other] = $this->cabinet();
        $payload = ['module' => 'seller', 'from' => '2026-01-01', 'to' => '2026-01-31'];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/settings/data/exports', [...$payload, 'module' => 'unknown'])->assertSessionHasErrors('module');
        $this->post('/settings/data/exports', [...$payload, 'to' => '2025-01-01'])->assertSessionHasErrors('to');
        self::assertSame(43200, (int) config('features.E1-11.defaults.export_ttl_minutes'));
        $this->post('/settings/data/exports', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $first = DB::table('data_exports')->first();
        self::assertTrue(Carbon::parse($first->expires_at)->equalTo(now()->addDays(30)));
        $feature = config('features.E1-11');
        unset($feature['defaults']['export_ttl_minutes']);
        config(['features.E1-11' => $feature]);
        $this->post('/settings/data/exports', $payload)->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('data_exports')->count());
        self::assertSame(2, DB::table('data_exports')->where('expires_at', now()->addDays(30))->count());
        config(['features.E1-11.defaults.export_ttl_minutes' => 60]);
        $this->post('/settings/data/exports', $payload)->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('data_exports')->where('expires_at', now()->addHour())->count());
        self::assertTrue(Carbon::parse(DB::table('data_exports')->where('id', $first->id)->value('expires_at'))->equalTo(now()->addDays(30)));
        $shown = collect($this->get('/settings/data')->assertOk()->inertiaProps('exports'))->firstWhere('id', $first->id);
        self::assertTrue(Carbon::parse($shown['expires_at'])->equalTo(now()->addDays(30)));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/settings/data/exports/'.$first->id.'/download')->assertNotFound();
        [$member] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->post('/settings/data/exports', $payload)->assertForbidden();
        Carbon::setTestNow();
    }
}
