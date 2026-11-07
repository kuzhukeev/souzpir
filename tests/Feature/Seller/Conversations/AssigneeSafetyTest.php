<?php

namespace Tests\Feature\Seller\Conversations;

use App\Seller\Conversations\AssigneeAudit;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Conversations\Models\SellerTask;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AssigneeSafetyTest extends TenancyTestCase
{
    public function test_instance_saves_and_save_quietly_require_active_member_brand_and_dialogue_write(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$wrongBrand] = $this->member($org, 'member', [$other->id]);
        [$disabled, $disabledMembership] = $this->member($org, 'member', [$brand->id]);
        $this->within($org, null, fn () => $disabledMembership->update(['status' => 'disabled']));
        $this->within($org, $brand, function () use ($editor, $manager, $viewer, $wrongBrand, $disabled): void {
            $conversation = Conversation::create(['assigned_user_id' => $editor->id, 'stage' => 'closed']);
            $task = SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup', 'assigned_user_id' => $manager->id]);
            self::assertSame($editor->id, $conversation->assigned_user_id);
            self::assertSame($manager->id, $task->assigned_user_id);
            foreach ([$viewer, $wrongBrand, $disabled] as $bad) {
                foreach ([$conversation, $task] as $record) {
                    $record->assigned_user_id = $bad->id;
                    foreach ([false, true] as $quiet) {
                        try {
                            $quiet ? $record->saveQuietly() : $record->save();
                            self::fail('Invalid assignee was accepted.');
                        } catch (LogicException $error) {
                            self::assertSame('Assignee cannot operate in this brand.', $error->getMessage());
                        }
                    }
                }
            }
            self::assertSame($editor->id, $conversation->fresh()->assigned_user_id);
            self::assertSame($manager->id, $task->fresh()->assigned_user_id);
        });
    }

    public function test_nested_transaction_rolls_back_instance_assignment_and_unassignment_is_allowed(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->within($org, $brand, function () use ($editor): void {
            $conversation = Conversation::create(['stage' => 'closed']);
            DB::beginTransaction();
            try {
                $conversation->assigned_user_id = $editor->id;
                self::assertTrue($conversation->saveQuietly());
                self::assertSame($editor->id, $conversation->fresh()->assigned_user_id);
            } finally {
                DB::rollBack();
            }
            self::assertNull($conversation->fresh()->assigned_user_id);
            $task = SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup', 'assigned_user_id' => $editor->id]);
            $task->assigned_user_id = null;
            self::assertTrue($task->saveQuietly());
            self::assertNull($task->fresh()->assigned_user_id);
        });
    }

    public function test_eloquent_bulk_assignment_is_detected_but_unrelated_updates_remain_available(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->within($org, $brand, function () use ($editor): void {
            $conversation = Conversation::create(['stage' => 'closed']);
            foreach ([
                fn () => Conversation::whereKey($conversation->id)->update(['assigned_user_id' => $editor->id]),
                fn () => SellerTask::query()->insert(['assigned_user_id' => $editor->id]),
                fn () => SellerTask::query()->upsert([['assigned_user_id' => $editor->id]], ['id']),
                fn () => SellerTask::query()->upsert([['id' => (string) Str::ulid()]], ['id'], ['assigned_user_id' => $editor->id]),
                fn () => Conversation::whereKey($conversation->id)->increment('version', 1, ['assigned_user_id' => $editor->id]),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Bulk assignee write was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Bulk assignee writes are forbidden.', $error->getMessage());
                }
            }
            self::assertSame(1, Conversation::whereKey($conversation->id)->update(['stage' => 'closed']));
            self::assertNull($conversation->fresh()->assigned_user_id);
        });
    }

    public function test_bulk_writes_cannot_move_or_create_records_across_tenants(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->within($org, $brand, function () use ($org, $brand, $other, $editor): void {
            $conversation = Conversation::create(['assigned_user_id' => $editor->id, 'stage' => 'closed']);
            $task = SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup', 'assigned_user_id' => $editor->id]);
            foreach ([$conversation, $task] as $record) {
                $class = $record::class;
                $operations = [
                    fn () => $class::whereKey($record->id)->update(['organization_id' => (string) Str::ulid()]),
                    fn () => $class::whereKey($record->id)->update(['brand_id' => $other->id]),
                    fn () => $class::whereKey($record->id)->update([$record->getTable().'.brand_id' => $other->id]),
                    fn () => $class::query()->insert(['id' => (string) Str::ulid(), 'organization_id' => (string) Str::ulid(), 'brand_id' => $other->id]),
                    fn () => $class::query()->insertOrIgnore(['id' => (string) Str::ulid()]),
                    fn () => $class::query()->insertUsing(['id', DB::raw('assigned_user_id')], DB::query()->selectRaw('1, 1')),
                    fn () => $class::query()->upsert([['id' => $record->id, 'brand_id' => $other->id]], ['id'], ['brand_id']),
                    fn () => $class::query()->updateOrInsert(['id' => $record->id], ['brand_id' => $other->id]),
                    fn () => $class::query()->fillAndInsert(['brand_id' => $other->id]),
                    fn () => $class::whereKey($record->id)->increment('brand_id'),
                    fn () => $class::whereKey($record->id)->decrement('version', 1, ['organization_id' => $org->id]),
                    fn () => $class::whereKey($record->id)->incrementEach(['brand_id' => 1]),
                    fn () => $class::whereKey($record->id)->decrementEach(['version' => 1], ['assigned_user_id' => $editor->id]),
                ];
                foreach ($operations as $operation) {
                    try {
                        $operation();
                        self::fail('Bulk write bypassed tenant validation.');
                    } catch (LogicException $error) {
                        self::assertSame('Bulk assignee writes are forbidden.', $error->getMessage());
                    }
                }
                self::assertSame($brand->id, $record->fresh()->brand_id);
                self::assertSame($org->id, $record->fresh()->organization_id);
                self::assertSame($editor->id, $record->fresh()->assigned_user_id);
            }
        });
    }

    public function test_historical_invalid_assignments_are_reported_and_not_silently_changed(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id, $other->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id, $other->id]);
        [$conversation, $task] = $this->within($org, $brand, function () use ($editor): array {
            $conversation = Conversation::create(['assigned_user_id' => $editor->id, 'stage' => 'closed']);

            return [$conversation, SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup', 'assigned_user_id' => $editor->id])];
        });
        $this->writeLegacyFixture('conversations', 'seller_conversation_assignment_guard', $conversation->id, $viewer->id);
        $this->writeLegacyFixture('seller_tasks', 'seller_task_assignment_guard', $task->id, $viewer->id);
        $this->within($org, null, fn () => self::assertSame(['conversations' => ['examined' => 1, 'invalid' => 1], 'seller_tasks' => ['examined' => 1, 'invalid' => 1]], app(AssigneeAudit::class)->countInvalid(app(TenantContext::class), $brand->id)));
        $this->within($org, $brand, function () use ($viewer, $conversation): void {
            $record = Conversation::findOrFail($conversation->id);
            $record->stage = 'closed';
            try {
                $record->saveQuietly();
                self::fail('Historical invalid assignment was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Assignee cannot operate in this brand.', $error->getMessage());
            }
            self::assertSame($viewer->id, DB::table('conversations')->where('id', $conversation->id)->value('assigned_user_id'));
            $record->assigned_user_id = null;
            self::assertTrue($record->save());
            self::assertNull($record->fresh()->assigned_user_id);
        });
        $this->within($org, null, fn () => self::assertSame(['conversations' => ['examined' => 0, 'invalid' => 0], 'seller_tasks' => ['examined' => 0, 'invalid' => 0]], app(AssigneeAudit::class)->countInvalid(app(TenantContext::class), $other->id)));
        $archived = $this->within($org, $other, fn () => Conversation::create(['assigned_user_id' => $editor->id, 'stage' => 'closed']));
        $this->writeLegacyFixture('conversations', 'seller_conversation_assignment_guard', $archived->id, $viewer->id);
        DB::table('brands')->where('id', $other->id)->update(['status' => 'archived']);
        $this->within($org, null, fn () => self::assertSame(['conversations' => ['examined' => 1, 'invalid' => 1], 'seller_tasks' => ['examined' => 0, 'invalid' => 0]], app(AssigneeAudit::class)->countInvalid(app(TenantContext::class), $other->id)));
    }

    public function test_commit_turn_rejects_viewer_task_and_rolls_back_output(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$conversation, $message] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => []]);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Fixture', 'meta' => []]);

            return [$conversation, $message];
        });
        $context = new TenantContext($org->id, $brand->id, source: 'job');
        $repo = app(ConversationRepository::class);
        $claim = $repo->claimTurn($context, new TurnCommand($conversation->id, $message->id, $message->text, null, 'assignee-fixture', 0));
        $taskId = (string) Str::ulid();
        $outcome = new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, null, null, null, [], [], tasks: [['id' => $taskId, 'type' => 'followup', 'assigned_user_id' => $viewer->id]]);
        try {
            $repo->commitTurn($claim, $outcome);
            self::fail('Viewer task was accepted.');
        } catch (HttpException $error) {
            self::assertSame(422, $error->getStatusCode());
        }
        self::assertDatabaseMissing('seller_tasks', ['id' => $taskId]);
        self::assertDatabaseHas('seller_turns', ['id' => $claim->turnId, 'status' => 'processing']);
        self::assertSame(0, $repo->find($context, $conversation->id)->version);
    }

    /** Only within the rolled-back test transaction: emulate records predating the guard. */
    private function writeLegacyFixture(string $table, string $trigger, string $id, string $userId): void
    {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
        try {
            DB::table($table)->where('id', $id)->update(['assigned_user_id' => $userId]);
        } finally {
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
        }
    }
}
