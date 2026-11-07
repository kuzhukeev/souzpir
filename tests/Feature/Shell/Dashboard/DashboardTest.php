<?php

namespace Tests\Feature\Shell\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DashboardTest extends TenancyTestCase
{
    public function test_mixed_feed_read_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $event = (string) Str::ulid();
        $delivery = (string) Str::ulid();
        $notification = (string) Str::ulid();
        $batch = (string) Str::ulid();
        DB::table('outbox_events')->insert(['id' => $event, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => '{}', 'created_at' => now()]);
        DB::table('notification_deliveries')->insert(['id' => $delivery, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_id' => $event, 'channel' => 'cabinet', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cabinet_notifications')->insert(['id' => $notification, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'delivery_id' => $delivery, 'event_key' => 'seller.promise.overdue', 'title' => 'Обещание', 'created_at' => now()]);
        DB::table('seller_digest_batches')->insert(['id' => $batch, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'channel' => 'cabinet', 'slot_date' => now()->toDateString(), 'due_at' => now(), 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_digest_cabinet_notifications')->insert(['batch_id' => $batch, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'titles' => json_encode(['Сводка']), 'event_keys' => json_encode(['seller.promise.overdue']), 'created_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/profile')->assertOk()->assertInertia(fn ($page) => $page->where('unread', 2));
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Shell/Dashboard/Index')->where('unread', 2));
        $this->get('/dashboard/overview')->assertOk()->assertInertia(fn ($page) => $page->has('items', 2)->where('unread', 2)->where('canVision', true)->where('vision', null)->has('usage', 2));
        $this->post('/dashboard/notifications/event/'.$notification.'/read')->assertRedirect();
        $this->post('/dashboard/notifications/digest/'.$batch.'/read')->assertRedirect();
        $this->get('/dashboard/overview')->assertOk()->assertInertia(fn ($page) => $page->where('unread', 0));
        $this->get('/profile')->assertOk()->assertInertia(fn ($page) => $page->where('unread', 0));
        $this->post('/dashboard/notifications/event/not-a-real-id/read')->assertNotFound();
        DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', 'seller')->update(['enabled' => false]);
        $this->get('/dashboard/overview')->assertOk()->assertInertia(fn ($page) => $page->has('items', 0));
        $this->post('/dashboard/notifications/digest/'.$batch.'/read')->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/dashboard/overview')->assertOk()->assertInertia(fn ($page) => $page->has('items', 0));
        $this->post('/dashboard/notifications/digest/'.$batch.'/read')->assertNotFound();
    }
}
