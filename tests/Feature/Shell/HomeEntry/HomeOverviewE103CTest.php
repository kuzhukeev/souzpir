<?php

namespace Tests\Feature\Shell\HomeEntry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class HomeOverviewE103CTest extends TenancyTestCase
{
    public function test_pages_require_a_session_and_respect_brand_access(): void
    {
        $this->get('/attention')->assertRedirect('/login');
        $this->get('/notification-center')->assertRedirect('/login');
        [$owner, $organization, $brand, $other] = $this->cabinet();
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/attention')->assertOk()->assertInertia(fn ($p) => $p->component('Shell/Dashboard/Attention')->where('seller', null));
        $this->get('/notification-center')->assertOk()->assertInertia(fn ($p) => $p->component('Shell/Notifications/Index')->has('items', 0)->where('unread', 0));
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/notification-center', ['X-Brand-Id' => $other->id])->assertNotFound();
    }

    public function test_read_all_only_marks_visible_notifications_of_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $ids = [];
        foreach ([$brand, $other] as $item) {
            $event = (string) Str::ulid();
            $delivery = (string) Str::ulid();
            $id = (string) Str::ulid();
            DB::table('outbox_events')->insert(['id' => $event, 'organization_id' => $org->id, 'brand_id' => $item->id, 'envelope' => '{}', 'created_at' => now()]);
            DB::table('notification_deliveries')->insert(['id' => $delivery, 'organization_id' => $org->id, 'brand_id' => $item->id, 'user_id' => $owner->id, 'event_id' => $event, 'channel' => 'cabinet', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cabinet_notifications')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'user_id' => $owner->id, 'delivery_id' => $delivery, 'event_key' => 'shell.member.invited', 'title' => 'Участник приглашён', 'created_at' => now()]);
            $ids[] = $id;
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/notification-center')->assertOk()->assertInertia(fn ($p) => $p->has('items', 1)->where('items.0.group', 'shell')->where('unread', 1));
        $this->post('/notification-center/read-all')->assertRedirect();
        self::assertNotNull(DB::table('cabinet_notifications')->where('id', $ids[0])->value('read_at'));
        self::assertNull(DB::table('cabinet_notifications')->where('id', $ids[1])->value('read_at'));
    }
}
