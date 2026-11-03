<?php

namespace Tests\Feature\Seller\Customers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TaskBoardTest extends TenancyTestCase
{
    public function test_status_filters_and_drawer_respect_brand_and_dialog_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = [];
        foreach ([[$brand, $manager], [$brand, $owner], [$other, $owner]] as [$item, $assignee]) {
            $dialog = (string) Str::ulid();
            $task = (string) Str::ulid();
            $ids[] = $task;
            DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $item->id, 'assigned_user_id' => $assignee->id, 'mode' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $dialog, 'role' => 'client', 'text' => 'Сообщение '.$assignee->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_tasks')->insert(['id' => $task, 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $dialog, 'assigned_user_id' => $assignee->id, 'type' => 'promise', 'payload' => '{"text":"Прайс"}', 'due_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        DB::table('seller_tasks')->where('id', $ids[0])->update(['priority' => 'urgent']);
        $this->patch('/seller/customer-tasks/'.$ids[0], ['status' => 'in_progress'])->assertRedirect();
        $this->get('/seller/tasks?urgent=1')->assertOk()->assertInertia(fn ($page) => $page->has('tasks', 1)->where('tasks.0.id', $ids[0]));
        $this->assertDatabaseHas('seller_tasks', ['id' => $ids[0], 'status' => 'in_progress']);
        $this->get('/seller/tasks?overdue=1&type=promise')->assertOk()->assertInertia(fn ($page) => $page->has('tasks', 2));
        $this->patch('/seller/customer-tasks/'.$ids[2], ['status' => 'in_progress'])->assertNotFound();
        $this->patch('/seller/customer-tasks/'.$ids[0], ['status' => 'bad'])->assertSessionHasErrors('status');
        $this->get('/seller/tasks?type=bad')->assertSessionHasErrors('type');
        $response = $this->actingAs($manager)->withSession(['brand_id' => $brand->id])->get('/seller/tasks?mine=1&overdue=1');
        $response->assertOk()->assertInertia(fn ($page) => $page->has('tasks', 1)->where('tasks.0.id', $ids[0])->where('tasks.0.overdue', true)->where('tasks.0.can_read_dialog', true)->where('tasks.0.assignee_name', $manager->name)->has('tasks.0.messages', 1));
        $response = $this->get('/seller/tasks');
        $foreign = collect($response->inertiaProps('tasks'))->firstWhere('id', $ids[1]);
        $this->assertFalse($foreign['can_read_dialog']);
        $this->assertSame([], $foreign['messages']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->patch('/seller/customer-tasks/'.$ids[0], ['status' => 'done'])->assertForbidden();
    }
}
