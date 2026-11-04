<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Seller\Dialogs\DeliverManagerMessage;
use App\Seller\Dialogs\ManagerMessageTransport;
use App\Seller\Dialogs\MessageTemplates;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DialogProfileTest extends TenancyTestCase
{
    public function test_templates_are_loaded_with_selected_brand_context(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $templates = $this->mock(MessageTemplates::class);
        $templates->shouldReceive('forBrand')->once()->withArgs(fn (TenantContext $context) => $context->brandId === $brand->id && $context->organizationId === $org->id)
            ->andReturn([['name' => 'Example', 'text' => 'Hello']]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/dialogs')
            ->assertOk()->assertInertia(fn ($page) => $page->where('messageTemplates.0.name', 'Example'));
    }

    public function test_profile_saves_only_brand_dialog_and_validates_fields(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $mine = $this->conversation($org->id, $brand->id);
        $foreign = $this->conversation($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->put('/seller/dialogs/'.$mine.'/profile', ['summary' => 'Важный клиент', 'slots' => ['city' => 'Казань', 'categories' => 'Игрушки, Посуда']])->assertRedirect();
        $row = DB::table('conversations')->where('id', $mine)->first();
        self::assertSame('Важный клиент', $row->summary);
        self::assertSame(['Игрушки', 'Посуда'], json_decode($row->state, true)['slots']['categories']);
        self::assertSame('Казань', json_decode($row->state, true)['slots']['city']);
        $this->put('/seller/dialogs/'.$foreign.'/profile', ['summary' => 'Чужой', 'slots' => ['city' => 'Москва']])->assertNotFound();
        $this->putJson('/seller/dialogs/'.$mine.'/profile', ['slots' => ['unknown' => 'x']])->assertUnprocessable();
        $this->putJson('/seller/dialogs/'.$mine.'/profile', ['slots' => ['city' => str_repeat('x', 501)]])->assertUnprocessable();
    }

    public function test_viewer_and_unassigned_manager_cannot_edit(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $dialog = $this->conversation($org->id, $brand->id);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->put('/seller/dialogs/'.$dialog.'/profile', ['slots' => ['city' => 'Казань']])->assertForbidden();
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id])->put('/seller/dialogs/'.$dialog.'/profile', ['slots' => ['city' => 'Казань']])->assertNotFound();
    }

    public function test_manager_message_is_queued_for_fake_delivery_with_brand_and_permission_checks(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $mine = $this->conversation($org->id, $brand->id);
        $foreign = $this->conversation($org->id, $other->id);
        DB::table('conversations')->whereIn('id', [$mine, $foreign])->update(['mode' => 'manager', 'assigned_user_id' => $owner->id]);
        Queue::fake();
        Storage::fake('local');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/dialogs/'.$mine.'/messages', ['text' => 'Добрый день'])->assertRedirect();
        $message = DB::table('messages')->where('conversation_id', $mine)->first();
        self::assertSame('manager', $message->role);
        self::assertSame('Добрый день', $message->text);
        Queue::assertPushed(DeliverManagerMessage::class, fn ($job) => $job->messageId === $message->id && $job->brandId === $brand->id);
        $transport = $this->mock(ManagerMessageTransport::class);
        $transport->shouldReceive('send')->once()->with($org->id, $brand->id, $mine, $message->id);
        (new DeliverManagerMessage($org->id, $other->id, $mine, $message->id))->handle();
        (new DeliverManagerMessage($org->id, $brand->id, $mine, $message->id))->handle();
        self::assertNotNull(DB::table('messages')->where('id', $message->id)->value('delivered_at'));
        $this->post('/seller/dialogs/'.$mine.'/messages', ['attachment' => UploadedFile::fake()->create('document.pdf', 5, 'application/pdf')])->assertRedirect();
        $attachment = DB::table('messages')->where('conversation_id', $mine)->where('text', 'Вложение')->first();
        $this->get('/seller/dialogs/'.$mine.'/messages/'.$attachment->id.'/attachment')->assertOk();
        $this->get('/seller/dialogs/'.$foreign.'/messages/'.$attachment->id.'/attachment')->assertNotFound();
        $this->postJson('/seller/dialogs/'.$mine.'/messages', ['attachment' => UploadedFile::fake()->create('program.exe', 5, 'application/octet-stream')])->assertUnprocessable();
        $this->post('/seller/dialogs/'.$foreign.'/messages', ['text' => 'Чужой'])->assertNotFound();
        $this->postJson('/seller/dialogs/'.$mine.'/messages', ['text' => ''])->assertUnprocessable();
        $this->postJson('/seller/dialogs/'.$mine.'/messages', ['text' => str_repeat('x', 5001)])->assertUnprocessable();
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/dialogs/'.$mine.'/messages', ['text' => 'Нет доступа'])->assertForbidden();
    }

    public function test_transfer_requires_brand_access_and_records_history(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$foreignManager] = $this->member($org, 'member', [$other->id], ['sales_manager' => true]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $dialog = $this->conversation($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/dialogs/'.$dialog.'/transfer', ['assigned_user_id' => $foreignManager->id])->assertUnprocessable();
        $this->postJson('/seller/dialogs/'.$dialog.'/transfer', ['assigned_user_id' => 'invalid'])->assertUnprocessable();
        $this->post('/seller/dialogs/'.$dialog.'/transfer', ['assigned_user_id' => $manager->id])->assertRedirect();
        $this->assertDatabaseHas('conversations', ['id' => $dialog, 'mode' => 'manager', 'assigned_user_id' => $manager->id]);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $dialog, 'action' => 'seller.dialog.transferred']);
        $this->get('/seller/dialogs/'.$dialog)->assertOk()->assertInertia(fn ($page) => $page->has('dialog.transfers', 1));
    }

    public function test_reminder_is_created_for_current_dialog_and_rejects_past_due_date(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $mine = $this->conversation($org->id, $brand->id);
        $foreign = $this->conversation($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $data = ['text' => 'Уточнить доставку', 'due_at' => now()->addDay()->toIso8601String()];
        $this->post('/seller/dialogs/'.$mine.'/tasks', $data)->assertRedirect();
        $this->assertDatabaseHas('seller_tasks', ['conversation_id' => $mine, 'brand_id' => $brand->id, 'assigned_user_id' => $owner->id, 'type' => 'reminder']);
        $this->post('/seller/dialogs/'.$foreign.'/tasks', $data)->assertNotFound();
        $this->postJson('/seller/dialogs/'.$mine.'/tasks', ['text' => 'Проверить', 'due_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();
        $this->postJson('/seller/dialogs/'.$mine.'/tasks', ['text' => '', 'due_at' => now()->addDay()->toIso8601String()])->assertUnprocessable();
    }

    private function conversation(string $organization, string $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand, 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
