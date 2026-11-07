<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReviewsE510Test extends TenancyTestCase
{
    public function test_list_filters_dismissal_and_access_are_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $create = function (string $brandId, ?string $assignee) use ($org, $owner): string {
            $dialog = (string) Str::ulid();
            $message = (string) Str::ulid();
            $review = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brandId, 'state' => '{}', 'mode' => 'manager', 'assigned_user_id' => $assignee, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brandId, 'conversation_id' => $dialog, 'role' => 'agent', 'text' => 'Ответ', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('message_reviews')->insert(['id' => $review, 'organization_id' => $org->id, 'brand_id' => $brandId, 'message_id' => $message, 'user_id' => $owner->id, 'type' => 'wrong_fact', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);

            return $review;
        };
        $mine = $create($brand->id, $manager->id);
        $unassigned = $create($brand->id, null);
        $foreign = $create($other->id, null);
        $this->get('/seller/reviews')->assertRedirect();
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id])->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Seller/Reviews/Index', 'X-Inertia-Partial-Data' => 'reviews'])->get('/seller/reviews?type=wrong_fact')->assertOk()->assertJsonCount(1, 'props.reviews.items')->assertJsonPath('props.reviews.items.0.id', $mine);
        $this->postJson('/seller/reviews/dismiss', ['ids' => [$unassigned]])->assertNotFound();
        $this->postJson('/seller/reviews/dismiss', ['ids' => [$foreign]])->assertNotFound();
        $this->postJson('/seller/reviews/dismiss', ['ids' => []])->assertUnprocessable();
        $this->getJson('/seller/reviews?type=invalid')->assertUnprocessable();
        $this->post('/seller/reviews/dismiss', ['ids' => [$mine]])->assertRedirect();
        $this->assertDatabaseHas('message_reviews', ['id' => $mine, 'status' => 'dismissed']);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/reviews/dismiss', ['ids' => [$unassigned]])->assertForbidden();
    }
}
