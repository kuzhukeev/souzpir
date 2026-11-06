<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CloseAndTaskE509Test extends TenancyTestCase
{
    public function test_close_and_complete_task_are_scoped_and_audited(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $dialog = (string) Str::ulid();
        $foreign = (string) Str::ulid();
        foreach ([[$dialog, $brand], [$foreign, $other]] as [$id, $item]) {
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'mode' => 'bot', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $task = (string) Str::ulid();
        DB::table('seller_tasks')->insert(['id' => $task, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $dialog, 'type' => 'reminder', 'status' => 'new', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->from('/seller/dialogs/mine/'.$dialog)->post('/seller/dialogs/'.$dialog.'/tasks', ['text' => 'Напомнить', 'due_at' => now()->addDay()->toIso8601String()])->assertRedirect('/seller/dialogs/mine/'.$dialog);
        $listTask = DB::table('seller_tasks')->where('conversation_id', $dialog)->where('id', '!=', $task)->value('id');
        $this->from('/seller/tasks')->patch('/seller/customer-tasks/'.$listTask, ['status' => 'done'])->assertRedirect('/seller/tasks');
        $this->assertDatabaseHas('seller_tasks', ['id' => $listTask, 'status' => 'done']);
        $this->from('/seller/dialogs/mine/'.$dialog)->post('/seller/dialogs/'.$dialog.'/tasks/'.$task.'/complete')->assertRedirect('/seller/dialogs/mine/'.$dialog);
        $this->assertDatabaseHas('seller_tasks', ['id' => $task, 'status' => 'done']);
        $this->post('/seller/dialogs/'.$dialog.'/tasks/'.$task.'/complete')->assertSessionHasErrors('task');
        $this->post('/seller/dialogs/'.$foreign.'/mode', ['action' => 'close'])->assertNotFound();
        $this->post('/seller/dialogs/'.$dialog.'/mode', ['action' => 'invalid'])->assertSessionHasErrors('action');
        $this->post('/seller/dialogs/'.$dialog.'/mode', ['action' => 'close'])->assertRedirect('/seller/dialogs/'.$dialog);
        $this->assertDatabaseHas('conversations', ['id' => $dialog, 'stage' => 'done', 'mode' => 'paused']);
        self::assertSame('done', json_decode(DB::table('conversations')->where('id', $dialog)->value('state'), true)['stage']);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $dialog, 'action' => 'seller.dialog.closed']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/dialogs/'.$dialog.'/mode', ['action' => 'close'])->assertForbidden();
        $this->post('/seller/dialogs/'.$dialog.'/tasks/'.$task.'/complete')->assertForbidden();
    }
}
