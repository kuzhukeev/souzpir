<?php

namespace Tests\Feature\Shell\HomeEntry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class HomeOverviewE103DTest extends TenancyTestCase
{
    public function test_period_and_snooze_respect_brand_and_expiration(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'mode' => 'manager', 'handed_off_at' => now(), 'created_at' => now()->subDays(10), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/dashboard?days=7')->assertOk()->assertInertia(fn ($p) => $p->where('seller.total', 0));
        $this->get('/dashboard?days=30')->assertOk()->assertInertia(fn ($p) => $p->where('seller.total', 1)->where('days', 30));
        $this->getJson('/dashboard?days=17')->assertUnprocessable();
        $this->post('/attention/not_real/snooze')->assertNotFound();
        $this->post('/attention/manager/snooze')->assertRedirect();
        $this->get('/attention')->assertOk()->assertInertia(fn ($p) => $p->where('seller.attention', []));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/attention')->assertOk()->assertInertia(fn ($p) => $p->has('seller.attention', 1));
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $this->travel(8)->days();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/attention')->assertOk()->assertInertia(fn ($p) => $p->has('seller.attention', 1));
    }

    public function test_notification_center_can_read_older_than_first_hundred(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $oldest = null;
        foreach (range(1, 101) as $number) {
            $event = (string) Str::ulid();
            $delivery = (string) Str::ulid();
            $id = (string) Str::ulid();
            $created = now()->subMinutes(102 - $number);
            DB::table('outbox_events')->insert(['id' => $event, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => '{}', 'created_at' => $created]);
            DB::table('notification_deliveries')->insert(['id' => $delivery, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_id' => $event, 'channel' => 'cabinet', 'status' => 'sent', 'created_at' => $created, 'updated_at' => $created]);
            DB::table('cabinet_notifications')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'delivery_id' => $delivery, 'event_key' => 'shell.member.invited', 'title' => 'Участник приглашён', 'created_at' => $created]);
            $oldest ??= $id;
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/notification-center')->assertOk()->assertInertia(fn ($p) => $p->has('items', 101)->where('unread', 101));
        $this->post('/dashboard/notifications/event/'.$oldest.'/read')->assertRedirect();
        self::assertNotNull(DB::table('cabinet_notifications')->where('id', $oldest)->value('read_at'));
        $this->get('/notification-center')->assertOk()->assertInertia(fn ($p) => $p->has('items', 101)->where('unread', 100));
    }
}
