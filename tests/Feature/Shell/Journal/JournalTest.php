<?php

namespace Tests\Feature\Shell\Journal;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\Jobs\DeliverNotification;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class JournalTest extends TenancyTestCase
{
    public function test_audit_filters_are_scoped_and_editor_cannot_see_cabinet_events(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            app(AuditWriter::class)->record($ctx, new AuditEntry('knowledge.fact.updated', 'fact', 'F1', ['text' => 'old'], ['text' => 'new']));
            app(AuditWriter::class)->record($ctx, new AuditEntry('account.email.verified', 'user', 'U1'));
        });
        $otherCtx = new TenantContext($org->id, $other->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($otherCtx, fn () => app(AuditWriter::class)->record($otherCtx, new AuditEntry('knowledge.fact.updated', 'fact', 'FOREIGN')));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/journal?module=seller')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Shell/Journal/Actions', false)->has('rows.data', 1)->where('rows.data.0.subject_id', 'F1'));
        $this->get('/journal')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));
        $this->get('/journal?actor='.$owner->id)->assertInertia(fn (Assert $p) => $p->has('rows.data', 2)->has('actors', 1)->has('actions', 2));
        $this->get('/journal?action=account.email.verified')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.subject_id', 'U1'));
        $this->get('/journal?module=vision')->assertInertia(fn (Assert $p) => $p->has('rows.data', 0));
        $this->get('/journal?from=2100-01-01')->assertInertia(fn (Assert $p) => $p->has('rows.data', 0));
        $this->get('/journal?to=2100-01-01')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));
        DB::table('audit_logs')->where('subject_id', 'F1')->update(['created_at' => '2026-10-30 21:30:00+00']);
        DB::table('audit_logs')->where('subject_id', 'U1')->update(['created_at' => '2026-10-30 20:59:00+00']);
        $this->get('/journal?from=2026-10-31&to=2026-10-31')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.subject_id', 'F1'));
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->flushSession()->actingAs($editor)->withSession(['brand_id' => $brand->id]);
        $this->get('/journal')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.subject_id', 'F1'));
        $this->getJson('/journal/notifications')->assertForbidden();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->flushSession()->actingAs($viewer)->withSession(['brand_id' => $brand->id])->getJson('/journal')->assertForbidden();
    }

    public function test_csv_escapes_spreadsheet_formulas_and_respects_brand_scope(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $owner->forceFill(['name' => '=HYPERLINK("https://example.test")'])->save();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, fn () => app(AuditWriter::class)->record($ctx, new AuditEntry('knowledge.fact.updated', 'fact', '+1+1')));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->get('/journal/export');
        $response->assertOk();
        $csv = $response->streamedContent();
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringContainsString("'+1+1", $csv);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_retry_only_queues_known_failed_delivery_and_is_authorized(): void
    {
        Queue::fake();
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        app(NotificationDispatcher::class)->enqueue($event);
        $id = (string) Str::ulid();
        DB::table('notification_deliveries')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_id' => $event->envelope['event_id'], 'user_id' => $owner->id, 'channel' => 'email', 'status' => 'failed', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/journal/notifications?status=failed')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Shell/Journal/Notifications', false)->has('rows.data', 1));
        $this->get('/journal/notifications?channel=telegram')->assertInertia(fn (Assert $p) => $p->has('rows.data', 0));
        $this->get('/journal/notifications?channel=email&status=sent')->assertInertia(fn (Assert $p) => $p->has('rows.data', 0));
        $this->get('/journal/notifications?to=2100-01-01')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));
        $this->post('/journal/notifications/'.$id.'/retry')->assertRedirect();
        $this->assertDatabaseHas('notification_deliveries', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.delivery.retry_requested', 'subject_id' => $id]);
        Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->deliveryId === $id);
        $this->postJson('/journal/notifications/'.$id.'/retry')->assertStatus(409);
        DB::table('notification_deliveries')->where('id', $id)->update(['status' => 'uncertain']);
        $this->postJson('/journal/notifications/'.$id.'/retry')->assertStatus(409);
        $this->withSession(['brand_id' => $other->id])->postJson('/journal/notifications/'.$id.'/retry')->assertNotFound();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->flushSession()->actingAs($editor)->withSession(['brand_id' => $brand->id])->postJson('/journal/notifications/'.$id.'/retry')->assertForbidden();
    }

    public function test_retry_persists_job_atomically_with_delivery_state(): void
    {
        [$owner, $brand, $id] = $this->failedDelivery();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/journal/notifications/'.$id.'/retry')->assertRedirect();
        $this->assertDatabaseHas('notification_deliveries', ['id' => $id, 'status' => 'pending']);
        self::assertSame(1, DB::table('jobs')->count());
        self::assertStringContainsString('DeliverNotification', DB::table('jobs')->value('payload'));
    }

    public function test_failed_database_queue_insert_rolls_back_retry(): void
    {
        [$owner, $brand, $id] = $this->failedDelivery();
        config(['queue.connections.database.table' => 'journal_test_missing_jobs']);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/journal/notifications/'.$id.'/retry');
            self::fail('Queue insertion should have failed.');
        } catch (QueryException) {
            // An unavailable queue must roll back both state and audit.
        }
        $this->assertDatabaseHas('notification_deliveries', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'notification.delivery.retry_requested', 'subject_id' => $id]);
    }

    private function failedDelivery(): array
    {
        [$owner, $org, $brand] = $this->cabinet();
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        app(NotificationDispatcher::class)->enqueue($event);
        $id = (string) Str::ulid();
        DB::table('notification_deliveries')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_id' => $event->envelope['event_id'], 'user_id' => $owner->id, 'channel' => 'email', 'status' => 'failed', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $brand, $id];
    }
}
