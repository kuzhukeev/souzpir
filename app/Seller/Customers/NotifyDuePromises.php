<?php

namespace App\Seller\Customers;

use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotifyDuePromises extends Command
{
    protected $signature = 'seller:notify-due-promises';

    protected $description = 'Создать уведомления о просроченных обещаниях';

    public function handle(NotificationDispatcher $notifications): int
    {
        $tasks = DB::table('seller_tasks')->whereIn('type', ['promise', 'reminder'])->where('status', 'new')->whereNotNull('due_at')->where('due_at', '<=', now())->orderBy('due_at')->get();
        foreach ($tasks as $task) {
            DB::transaction(function () use ($task, $notifications): void {
                if (DB::table('seller_promise_notifications')->where('task_id', $task->id)->where('due_at', $task->due_at)->exists()) {
                    return;
                }
                $id = (string) Str::ulid();
                DB::table('seller_promise_notifications')->insert(['id' => $id, 'task_id' => $task->id, 'due_at' => $task->due_at, 'created_at' => now()]);
                $notifications->enqueue(new DomainEvent(['event_id' => $id, 'schema_version' => 1, 'type' => 'seller.promise.overdue',
                    'occurred_at' => now()->toIso8601String(), 'organization_id' => $task->organization_id, 'brand_id' => $task->brand_id,
                    'actor_id' => null, 'aggregate' => ['type' => 'seller_task', 'id' => $task->id, 'version' => 1],
                    'data' => ['task_id' => $task->id, 'due_at' => $task->due_at]]));
            });
        }

        return self::SUCCESS;
    }
}
