<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Seller\Tasks\NotifyDialogEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DialogEventNotificationsE509Test extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.notification_driver' => 'fake']);
        Queue::fake();
    }

    private function conversation(string $organization, string $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand, 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function drain(string $organizationId, string $brandId, string $conversationId, string $eventKey, array $data): void
    {
        (new NotifyDialogEvent($organizationId, $brandId, $conversationId, $eventKey, $data))->handle();
    }

    public function test_transfer_notifies_manager_and_isolates_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$foreignManager] = $this->member($org, 'member', [$other->id], ['sales_manager' => true]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $dialog = $this->conversation($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);

        $this->post('/seller/dialogs/'.$dialog.'/transfer', ['assigned_user_id' => $manager->id])->assertRedirect();

        Queue::assertPushed(NotifyDialogEvent::class, fn ($job): bool => $job->eventKey === 'seller.conversation.handed_off' && $job->conversationId === $dialog && $job->data['reason'] === 'manual_transfer');
        $this->drain($org->id, $brand->id, $dialog, 'seller.conversation.handed_off', ['reason' => 'manual_transfer', 'task_id' => (string) Str::ulid()]);

        $this->assertDatabaseHas('cabinet_notifications', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => 'seller.conversation.handed_off', 'link' => '/seller/dialogs/'.$dialog]);
        $this->assertDatabaseHas('cabinet_notifications', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $manager->id, 'event_key' => 'seller.conversation.handed_off']);
        $this->assertDatabaseMissing('cabinet_notifications', ['user_id' => $foreignManager->id, 'event_key' => 'seller.conversation.handed_off']);
        $this->assertDatabaseHas('outbox_events', ['organization_id' => $org->id, 'brand_id' => $brand->id]);
    }

    public function test_reminder_task_notifies_manager_and_isolates_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$foreignManager] = $this->member($org, 'member', [$other->id], ['sales_manager' => true]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $dialog = $this->conversation($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);

        $this->post('/seller/dialogs/'.$dialog.'/tasks', ['text' => 'Уточнить доставку', 'due_at' => now()->addDay()->toIso8601String()])->assertRedirect();

        $taskId = DB::table('seller_tasks')->where('conversation_id', $dialog)->value('id');
        Queue::assertPushed(NotifyDialogEvent::class, fn ($job): bool => $job->eventKey === 'seller.contact.reminder' && $job->data === ['reminder_id' => $taskId, 'user_id' => $owner->id]);
        $this->drain($org->id, $brand->id, $dialog, 'seller.contact.reminder', ['reminder_id' => $taskId, 'user_id' => $owner->id]);

        $this->assertDatabaseHas('cabinet_notifications', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => 'seller.contact.reminder', 'link' => '/seller/dialogs/'.$dialog]);
        $this->assertDatabaseMissing('cabinet_notifications', ['user_id' => $foreignManager->id, 'event_key' => 'seller.contact.reminder']);
    }
}
