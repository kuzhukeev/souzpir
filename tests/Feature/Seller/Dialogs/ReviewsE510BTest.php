<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReviewsE510BTest extends TenancyTestCase
{
    public function test_period_pagination_and_brand_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $dialog = (string) Str::ulid();
        $message = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'state' => '{}', 'mode' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $dialog, 'role' => 'agent', 'text' => 'Ответ', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 0; $i < 27; $i++) {
            DB::table('message_reviews')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'message_id' => $message, 'user_id' => $owner->id, 'type' => 'wrong_fact', 'status' => 'new', 'created_at' => $i === 26 ? now()->subDays(40) : now(), 'updated_at' => now()]);
        }
        $this->get('/seller/reviews')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Seller/Reviews/Index', 'X-Inertia-Partial-Data' => 'reviews'])->get('/seller/reviews?period=30')->assertOk()->assertJsonCount(25, 'props.reviews.items')->assertJsonPath('props.reviews.pagination.total', 26)->assertJsonPath('props.reviews.pagination.last', 2)->assertJsonPath('props.reviews.counts.wrong_fact', 26);
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Seller/Reviews/Index', 'X-Inertia-Partial-Data' => 'reviews'])->get('/seller/reviews?period=30&page=2')->assertOk()->assertJsonCount(1, 'props.reviews.items')->assertJsonPath('props.reviews.pagination.current', 2);
        $this->getJson('/seller/reviews?period=bad')->assertUnprocessable();
        $this->getJson('/seller/reviews?page=0')->assertUnprocessable();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Seller/Reviews/Index', 'X-Inertia-Partial-Data' => 'reviews'])->get('/seller/reviews?period=30')->assertOk()->assertJsonCount(0, 'props.reviews.items')->assertJsonPath('props.reviews.pagination.total', 0);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Seller/Reviews/Index', 'X-Inertia-Partial-Data' => 'reviews'])->get('/seller/reviews')->assertOk()->assertJsonPath('props.reviews.canOperate', false);
    }
}
