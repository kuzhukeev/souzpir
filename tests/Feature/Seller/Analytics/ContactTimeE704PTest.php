<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ContactTimeE704PTest extends TenancyTestCase
{
    public function test_average_time_to_contact_respects_period_rights_and_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $fast = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $fast, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'stage' => 'done', 'state' => '{}', 'created_at' => '2026-11-02 10:00:00+00', 'updated_at' => now(), 'contact_received_at' => '2026-11-02 10:10:00+00']);
        $slow = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $slow, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'stage' => 'done', 'state' => '{}', 'created_at' => '2026-11-02 11:00:00+00', 'updated_at' => now(), 'contact_received_at' => '2026-11-02 11:30:00+00']);
        $old = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $old, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'stage' => 'done', 'state' => '{}', 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => now(), 'contact_received_at' => null]);
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'stage' => 'done', 'state' => '{}', 'created_at' => '2026-11-02 10:00:00+00', 'updated_at' => now(), 'contact_received_at' => '2026-11-02 10:01:00+00']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $query = '?from=2026-11-02&to=2026-11-02';
        $this->get('/seller/analytics'.$query)->assertOk()->assertInertia(fn ($page) => $page
            ->where('analytics.avgMinutesToContact', 20)
            ->where('analytics.total', 3));
        $csv = $this->get('/seller/analytics/export.csv'.$query)->assertOk()->streamedContent();
        self::assertStringContainsString('"Среднее время до контакта, мин";20', $csv);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/analytics'.$query)->assertOk()->assertInertia(fn ($page) => $page->where('analytics.avgMinutesToContact', 1));
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        DB::table('conversations')->whereIn('id', [$fast, $slow])->update(['contact_received_at' => null]);
        $this->get('/seller/analytics'.$query)->assertOk()->assertInertia(fn ($page) => $page->where('analytics.avgMinutesToContact', null));
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/analytics'.$query)->assertOk()
            ->assertInertia(fn ($page) => $page->where('analytics.avgMinutesToContact', null));
        DB::table('brand_modules')->where('organization_id', $org->id)->where('brand_id', $brand->id)->delete();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/analytics'.$query)->assertNotFound();
    }
}
