<?php

namespace Tests\Feature\Seller\Customers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CustomersOverviewTest extends TenancyTestCase
{
    public function test_customer_screen_fields_and_page_size_follow_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $contact = (string) Str::ulid();
        $channel = (string) Str::ulid();
        $conversation = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Анна', 'city' => 'Тверь', 'external_ids' => json_encode(['telegram' => '12345']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('channel_accounts')->insert(['id' => $channel, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'telegram', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact, 'channel_account_id' => $channel, 'state' => json_encode(['slots' => ['company' => 'Фейерверк', 'city' => 'Старая запись']]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation, 'type' => 'promise', 'payload' => '{}', 'assigned_user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_customer_notes')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact, 'author_id' => $owner->id, 'body' => 'Позвонить', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customers?per_page=10')->assertOk()->assertInertia(fn ($page) => $page->where('pagination.perPage', 10)->where('customers.0.company', 'Фейерверк')->where('customers.0.city', 'Тверь')->where('customers.0.channel', 'telegram'));
        $this->get('/seller/customers/'.$contact)->assertOk()->assertInertia(fn ($page) => $page->where('customer.company', 'Фейерверк')->where('customer.city', 'Тверь')->where('customer.external_ids.telegram', '12345')->where('conversations.0.channel', 'telegram')->where('tasks.0.assignee_name', $owner->name)->where('tasks.0.channel', 'telegram')->where('notes.0.author_name', $owner->name));
        $this->get('/seller/tasks')->assertOk()->assertInertia(fn ($page) => $page->where('tasks.0.contact_name', 'Анна'));
        $this->get('/seller/customers?per_page=11')->assertSessionHasErrors('per_page');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/customers')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 0));
        $this->get('/seller/customers/'.$contact)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id])->get('/seller/tasks')->assertForbidden();
    }

    public function test_pagination_best_outcome_and_brand_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $contact = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Уникальный клиент', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['full_contact', now()->subDay()], ['stale', now()]] as [$outcome, $date]) {
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact, 'outcome' => $outcome, 'state' => '{}', 'created_at' => $date, 'updated_at' => $date]);
        }
        for ($i = 0; $i < 20; $i++) {
            DB::table('contacts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Клиент '.$i, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('contacts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'name' => 'Чужой', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customers')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 20)->where('pagination.total', 21)->where('pagination.lastPage', 2));
        $this->get('/seller/customers?page=2')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1)->where('pagination.page', 2));
        $this->get('/seller/customers?search=Уникальный')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1)->where('customers.0.outcome', 'stale')->where('customers.0.best_outcome', 'full_contact')->where('pagination.total', 1));
        $this->get('/seller/customers?page=0')->assertSessionHasErrors('page');
        $this->get('/seller/customers?search=Чужой')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 0));
        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id])->get('/seller/customers/'.$contact)->assertNotFound();
    }
}
