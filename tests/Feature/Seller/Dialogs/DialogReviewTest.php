<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DialogReviewTest extends TenancyTestCase
{
    public function test_manager_can_review_own_dialog_and_cannot_review_another_brand_or_dialog(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id, $other->id], ['sales_manager' => true]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$dialog, $message] = $this->dialog($org->id, $brand->id, $manager->id);
        [$otherDialog, $otherMessage] = $this->dialog($org->id, $other->id, $manager->id);
        [$unassigned, $unassignedMessage] = $this->dialog($org->id, $brand->id, null);
        $url = '/seller/dialogs/'.$dialog.'/messages/'.$message.'/reviews';
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id]);
        $this->post($url, ['type' => 'wrong_fact', 'note' => 'Проверить', 'better_text' => 'Новый ответ'])->assertRedirect('/seller/dialogs/'.$dialog);
        $this->assertDatabaseHas('message_reviews', ['message_id' => $message, 'user_id' => $manager->id, 'type' => 'wrong_fact', 'status' => 'new']);
        $this->postJson($url, ['type' => 'invalid'])->assertUnprocessable();
        $this->postJson($url, ['type' => 'other', 'note' => str_repeat('x', 2001)])->assertUnprocessable();
        $this->post('/seller/dialogs/'.$otherDialog.'/messages/'.$otherMessage.'/reviews', ['type' => 'other'])->assertNotFound();
        $this->post('/seller/dialogs/'.$unassigned.'/messages/'.$unassignedMessage.'/reviews', ['type' => 'other'])->assertNotFound();
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url, ['type' => 'other'])->assertForbidden();
    }

    private function dialog(string $org, string $brand, ?string $manager): array
    {
        $dialog = (string) Str::ulid();
        $message = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org, 'brand_id' => $brand, 'state' => '{}', 'mode' => 'manager', 'assigned_user_id' => $manager, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => $message, 'organization_id' => $org, 'brand_id' => $brand, 'conversation_id' => $dialog, 'role' => 'agent', 'text' => 'Ответ', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return [$dialog, $message];
    }
}
