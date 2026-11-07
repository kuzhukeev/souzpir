<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReadMarkerE504STest extends TenancyTestCase
{
    public function test_opening_dialog_marks_client_messages_read_and_shows_in_list_and_thread(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enableSeller($org->id, $brand->id);
        $id = $this->dialog($org->id, $brand->id, 'Алексей', 'manager');
        $client = (string) Str::ulid();
        $manager = (string) Str::ulid();
        DB::table('messages')->insert([
            ['id' => $manager, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $id,
                'role' => 'manager', 'text' => 'Здравствуйте', 'meta' => '{}', 'created_at' => now()->subMinute(), 'updated_at' => now()],
            ['id' => $client, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $id,
                'role' => 'client', 'text' => 'Нужна доставка', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dialogs.0.id', $id)->where('dialogs.0.unread', true));
        $this->assertDatabaseHas('conversations', ['id' => $id, 'read_sequence' => 0, 'read_at' => null]);
        $this->get('/seller/dialogs/'.$id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dialog.id', $id)->where('dialog.messages.1.id', $client)
            ->where('dialog.messages.1.read_at', fn ($value) => $value !== null)
            ->where('dialog.messages.0.read_at', null));
        $conversation = DB::table('conversations')->where('id', $id)->first();
        $this->assertSame((int) $conversation->history_sequence, (int) $conversation->read_sequence);
        $this->assertNotNull($conversation->read_at);
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dialogs.0.unread', false));
    }

    public function test_read_marking_is_brand_isolated(): void
    {
        [$owner, $org, $brand, $otherBrand] = $this->cabinet();
        $this->enableSeller($org->id, $brand->id);
        $this->enableSeller($org->id, $otherBrand->id);
        $mine = $this->dialog($org->id, $brand->id, 'Иван', 'manager');
        $foreign = $this->dialog($org->id, $otherBrand->id, 'Иван', 'manager');
        DB::table('messages')->insert([
            ['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $mine,
                'role' => 'client', 'text' => 'Привет', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $foreignMessage = (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $otherBrand->id, 'conversation_id' => $foreign,
                'role' => 'client', 'text' => 'Привет', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs/'.$mine)->assertOk();
        $this->assertDatabaseHas('conversations', ['id' => $foreign, 'read_sequence' => 0, 'read_at' => null]);
        $this->assertDatabaseHas('messages', ['id' => $foreignMessage]);
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
