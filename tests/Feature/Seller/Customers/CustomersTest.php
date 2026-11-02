<?php

namespace Tests\Feature\Seller\Customers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CustomersTest extends TenancyTestCase
{
    private function fixture(string $org, string $brand, string $owner): array
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $contact = (string) Str::ulid();
        $conversation = (string) Str::ulid();
        $task = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org, 'brand_id' => $brand, 'name' => 'Клиент А', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org, 'brand_id' => $brand, 'contact_id' => $contact,
            'assigned_user_id' => $owner, 'state' => json_encode(['slots' => ['purpose' => 'для себя', 'city' => 'Тверь']]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'conversation_id' => $conversation,
            'role' => 'client', 'text' => 'Для себя', 'meta' => json_encode(['extraction' => ['slot_updates' => ['purpose' => 'для себя']]]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_tasks')->insert(['id' => $task, 'organization_id' => $org, 'brand_id' => $brand, 'conversation_id' => $conversation,
            'type' => 'promise', 'payload' => json_encode(['text' => 'Отправить прайс']), 'due_at' => now()->subHour(), 'assigned_user_id' => $owner, 'created_at' => now(), 'updated_at' => now()]);

        return [$contact, $task];
    }

    public function test_customer_history_notes_and_task_updates(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$contact, $task] = $this->fixture($org->id, $brand->id, $owner->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customers')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Customers/Index')->where('customers.0.type', 'for_self')->where('customers.0.tasks', 1));
        $this->get('/seller/customers?city=Тверь&type=for_self')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1));
        $this->get('/seller/customers?city=Казань')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 0));
        $this->get('/seller/customers?from=invalid')->assertSessionHasErrors('from');
        $this->get('/seller/customers/'.$contact)->assertOk()->assertInertia(fn ($page) => $page->where('history.0.slot', 'purpose')->where('history.0.value', 'для себя'));
        $this->post('/seller/customers/'.$contact.'/notes', ['body' => 'Связаться завтра'])->assertRedirect();
        $this->assertDatabaseHas('seller_customer_notes', ['contact_id' => $contact, 'body' => 'Связаться завтра']);
        Queue::fake();
        $this->artisan('seller:notify-due-promises')->assertSuccessful();
        $this->artisan('seller:notify-due-promises')->assertSuccessful();
        $this->assertDatabaseCount('seller_promise_notifications', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $event = json_decode(DB::table('outbox_events')->value('envelope'), true);
        self::assertSame('seller.promise.overdue', $event['type']);
        self::assertSame($task, $event['data']['task_id']);
        $this->get('/seller/tasks?mine=1&overdue=1')->assertOk()->assertInertia(fn ($page) => $page->where('tasks.0.id', $task));
        $this->patch('/seller/customer-tasks/'.$task, ['status' => 'done'])->assertRedirect();
        $this->assertDatabaseHas('seller_tasks', ['id' => $task, 'status' => 'done']);
        $this->post('/seller/customers/'.$contact.'/notes', ['body' => ''])->assertSessionHasErrors('body');
        $this->patch('/seller/customer-tasks/'.$task, ['status' => 'invalid'])->assertSessionHasErrors('status');
    }

    public function test_brand_isolation_and_viewer_access(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$contact, $task] = $this->fixture($org->id, $first->id, $owner->id);
        [$foreign] = $this->fixture($org->id, $second->id, $owner->id);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->get('/seller/customers/'.$foreign)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id]);
        $this->get('/seller/customers/'.$contact)->assertOk();
        $this->post('/seller/customers/'.$contact.'/notes', ['body' => 'Нет прав'])->assertForbidden();
        $this->get('/seller/tasks')->assertForbidden();
        $this->patch('/seller/customer-tasks/'.$task, ['status' => 'done'])->assertForbidden();
    }

    public function test_reminder_creation_validation_and_due_notification(): void
    {
        [$owner, $org, $brand, $otherBrand] = $this->cabinet();
        [$contact] = $this->fixture($org->id, $brand->id, $owner->id);
        [$foreign] = $this->fixture($org->id, $otherBrand->id, $owner->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $url = '/seller/customers/'.$contact.'/reminders';
        $data = ['text' => 'Позвонить клиенту', 'due_at' => now()->addHour()->toIso8601String(), 'manager_id' => $owner->id];
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $task = DB::table('seller_tasks')->where('type', 'reminder')->first();
        self::assertNotNull($task);
        self::assertSame($owner->id, $task->assigned_user_id);
        self::assertSame('Позвонить клиенту', json_decode($task->payload, true)['text']);
        $this->get('/seller/customers/'.$contact)->assertOk()->assertInertia(fn ($page) => $page->where('tasks.1.id', $task->id));
        $this->get('/seller/tasks?mine=1')->assertOk()->assertInertia(fn ($page) => $page->has('tasks', 2));
        $this->post($url, array_merge($data, ['text' => '']))->assertSessionHasErrors('text');
        $this->post($url, array_merge($data, ['due_at' => now()->subMinute()->toIso8601String()]))->assertSessionHasErrors('due_at');
        $this->post('/seller/customers/'.$foreign.'/reminders', $data)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url, $data)->assertForbidden();
        Queue::fake();
        DB::table('seller_tasks')->where('id', $task->id)->update(['due_at' => now()->subMinute()]);
        $this->artisan('seller:notify-due-promises')->assertSuccessful();
        $this->assertDatabaseHas('seller_promise_notifications', ['task_id' => $task->id]);
        self::assertTrue(DB::table('outbox_events')->get()->contains(fn ($row) => (json_decode($row->envelope, true)['data']['task_id'] ?? null) === $task->id));
    }
}
