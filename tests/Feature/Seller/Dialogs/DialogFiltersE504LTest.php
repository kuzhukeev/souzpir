<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DialogFiltersE504LTest extends TenancyTestCase
{
    public function test_filters_reviews_access_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $waiting = (string) Str::ulid();
        $closed = (string) Str::ulid();
        foreach ([[$waiting, $brand->id, 'manager', 'open', 'full_contact', now()], [$closed, $brand->id, 'bot', 'done', 'declined', now()->subDays(10)], [(string) Str::ulid(), $other->id, 'manager', 'open', 'full_contact', now()]] as [$id, $bid, $mode, $stage, $outcome, $created]) {
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $bid, 'state' => '{}', 'mode' => $mode, 'stage' => $stage, 'outcome' => $outcome, 'created_at' => $created, 'updated_at' => $created]);
        }
        $message = (string) Str::ulid();
        DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $waiting, 'role' => 'agent', 'text' => 'Ответ', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('message_reviews')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'message_id' => $message, 'user_id' => $owner->id, 'type' => 'unclear', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs?mode=waiting&outcome=full_contact&period=7&reviewed=1')->assertOk()->assertInertia(fn ($page) => $page->has('dialogs', 1)->where('dialogs.0.id', $waiting)->where('dialogs.0.review_count', 1));
        $this->get('/seller/dialogs?mode=closed')->assertOk()->assertInertia(fn ($page) => $page->has('dialogs', 1)->where('dialogs.0.id', $closed));
        $this->get('/seller/dialogs?outcome=bad')->assertSessionHasErrors('outcome');
        $this->get('/seller/dialogs?period=12')->assertSessionHasErrors('period');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs?reviewed=1')->assertOk()->assertInertia(fn ($page) => $page->has('dialogs', 1));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->get('/seller/dialogs?reviewed=1')->assertOk()->assertInertia(fn ($page) => $page->has('dialogs', 0));
    }

    public function test_initial_error_returns_retry_state(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::listen(function ($event): void {
            if (str_contains($event->sql, 'from "conversations"')) {
                throw new QueryException('pgsql', $event->sql, [], new RuntimeException('failure'));
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn ($page) => $page->where('loadError', true)->has('dialogs', 0));
    }
}
