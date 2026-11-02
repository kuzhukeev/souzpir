<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DialogsTest extends TenancyTestCase
{
    public function test_list_detail_slots_and_take_return_use_saved_conversation(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enableSeller($org->id, $brand->id);
        $id = $this->dialog($org->id, $brand->id, 'Мария', 'bot');
        DB::table('messages')->insert([
            ['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $id,
                'role' => 'client', 'text' => 'Нужна доставка', 'meta' => '{}', 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $id,
                'role' => 'agent', 'text' => 'Уточните город', 'meta' => json_encode(['intent' => 'delivery', 'goal' => 'city', 'guard' => 'passed', 'secret' => 'hidden']), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Dialogs/Index')->has('dialogs', 1)->where('dialogs.0.name', 'Мария'));
        $this->get('/seller/dialogs/'.$id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Dialogs/Index')->where('dialog.id', $id)->has('dialog.slots', 16)
            ->where('dialog.slots.1.value', 'Москва')->has('dialog.messages', 2)
            ->where('dialog.messages.1.meta.intent', 'delivery')->missing('dialog.messages.1.meta.secret'));
        $this->post('/seller/dialogs/'.$id.'/mode', ['action' => 'take'])->assertRedirect('/seller/dialogs/'.$id);
        $this->assertDatabaseHas('conversations', ['id' => $id, 'mode' => 'manager', 'assigned_user_id' => $owner->id]);
        $this->post('/seller/dialogs/'.$id.'/mode', ['action' => 'return'])->assertRedirect('/seller/dialogs/'.$id);
        $this->assertDatabaseHas('conversations', ['id' => $id, 'mode' => 'bot', 'assigned_user_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.dialog.mode_changed', 'subject_id' => $id, 'brand_id' => $brand->id]);
    }

    public function test_permissions_manager_scope_brand_isolation_and_invalid_input(): void
    {
        [$owner, $org, $brand, $otherBrand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->enableSeller($org->id, $brand->id);
        $hidden = $this->dialog($org->id, $brand->id, 'Скрытый', 'bot');
        $waiting = $this->dialog($org->id, $brand->id, 'Ожидает', 'manager');
        $foreign = $this->dialog($org->id, $otherBrand->id, 'Чужой', 'manager');
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('dialogs', 1)->where('dialogs.0.id', $waiting));
        $this->get('/seller/dialogs/'.$hidden)->assertNotFound();
        $this->get('/seller/dialogs/'.$foreign)->assertNotFound();
        $this->postJson('/seller/dialogs/'.$hidden.'/mode', ['action' => 'take'])->assertNotFound();
        $this->post('/seller/dialogs/'.$waiting.'/mode', ['action' => 'take'])->assertRedirect();
        $this->assertDatabaseHas('conversations', ['id' => $waiting, 'assigned_user_id' => $manager->id]);
        $this->flushSession()->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs')->assertOk();
        $this->postJson('/seller/dialogs/'.$waiting.'/mode', ['action' => 'return'])->assertForbidden();
        $this->flushSession()->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/dialogs/'.$waiting.'/mode', ['action' => 'invalid'])->assertUnprocessable();
        $this->getJson('/seller/dialogs?mode=invalid')->assertUnprocessable();
    }

    private function enableSeller(string $organization, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function dialog(string $organization, string $brand, string $name, string $mode): string
    {
        $contact = (string) Str::ulid();
        $id = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $organization, 'brand_id' => $brand,
            'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand,
            'contact_id' => $contact, 'state' => json_encode(['slots' => ['name' => $name, 'city' => 'Москва']]),
            'mode' => $mode, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
