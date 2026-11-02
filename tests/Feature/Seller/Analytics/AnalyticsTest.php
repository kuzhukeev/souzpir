<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AnalyticsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function conversation(string $org, string $brand, string $user): void
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'assigned_user_id' => $user,
            'client_type' => 'newbie', 'stage' => 'done', 'state' => json_encode(['slots' => ['email' => 'client@example.com']]),
            'handed_off_at' => '2026-11-02 12:01:00+00', 'taken_at' => '2026-11-02 12:02:00+00', 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => '2026-11-02 12:02:00+00']);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'conversation_id' => $id, 'role' => 'client', 'text' => 'Вопрос', 'meta' => '{"intent":"delivery"}', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_metrics_csv_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->conversation($org->id, $brand->id, $owner->id);
        $this->conversation($org->id, $other->id, $owner->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $url = '/seller/analytics?from=2026-11-02&to=2026-11-02';
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('analytics.total', 1)->where('analytics.contact', 1)->where('analytics.handed', 1)->where('analytics.closed', 1)->where('analytics.types.newbie', 1)->where('analytics.topics.delivery', 1)->where('analytics.managers.0.taken', 1));
        $response = $this->get('/seller/analytics/export.csv?from=2026-11-02&to=2026-11-02')->assertOk();
        $this->assertSame("Показатель;Значение\nДиалоги;1\n\"Контакт получен\";1\n\"Переданы менеджеру\";1\nЗавершены;1\n", $response->streamedContent());
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('analytics.total', 1));
        $this->get('/seller/analytics?from=2026-10-01&to=2026-10-31')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.total', 0));
    }

    public function test_permissions_and_invalid_period(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/analytics?from=2026-11-03&to=2026-11-02')->assertSessionHasErrors('to');
        $this->get('/seller/analytics?from=not-a-date&to=2026-11-02')->assertSessionHasErrors('from');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/analytics')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.canManage', false)->has('analytics.managers', 0));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->get('/seller/analytics')->assertNotFound();
    }
}
